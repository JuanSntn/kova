<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword2', 'password_confirmation' => 'NewPassword2'];
    }

    public function test_request_is_generic_normalized_and_throttled(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $first = $this->postJson('/api/auth/forgot-password', ['email' => ' OWNER@EXAMPLE.COM '])->assertOk()->json();
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])->assertExactJson($first);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertExactJson($first);
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_reset_revokes_sessions_rotates_remember_and_preserves_unverified_state(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'OldPassword1']);
        $oldRemember = $user->remember_token;
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'));
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword2', $user->password));
        $this->assertFalse(Hash::check('OldPassword1', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertGuest();
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassword2'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    }

    public function test_invalid_expired_and_other_users_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', $this->payload($user, 'tampered'))->assertUnprocessable();
        $this->postJson('/api/auth/reset-password', $this->payload($other, $token))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_validation_and_new_token_invalidate_previous_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $old = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [...$this->payload($user, $old), 'password' => 'weak'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/auth/reset-password', $this->payload($user, $old))->assertUnprocessable();
    }

    public function test_transport_failure_is_generic_and_does_not_log_secrets(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('secret-token-and-password'));
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('error')->once()->with('Password recovery mail failed', ['exception_type' => \RuntimeException::class]);
        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])->assertExactJson($response);
    }

    public function test_pending_verification_context_is_invalid_after_reset(): void
    {
        $user = User::factory()->unverified()->create();
        $context = ['user_id' => $user->id, 'email' => $user->email, 'expires_at' => now()->addHour()->timestamp, 'credential_fingerprint' => $user->verificationCredentialFingerprint()];
        $this->postJson('/api/auth/reset-password', $this->payload($user, Password::createToken($user)))->assertOk();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession(['email_verification' => $context])
            ->getJson('/api/auth/email/verification-status')->assertJsonPath('status', 'unavailable');
    }

    public function test_mail_link_uses_configured_frontend_and_fragment(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);
        $user = User::factory()->create();
        $mail = (new ResetPasswordNotification('secret-token'))->toMail($user);
        $this->assertStringStartsWith('https://app.example.com/reset-password#token=secret-token&email=', $mail->actionUrl);
        $this->assertNull(parse_url($mail->actionUrl, PHP_URL_QUERY));
    }

    public function test_two_processes_cannot_consume_the_same_token(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload($user, Password::createToken($user));
        $database = tempnam(sys_get_temp_dir(), 'kova-reset-test-');
        $processes = [];
        try {
            // Export only the three auth tables from the isolated in-memory test DB.
            $pdo = new \PDO('sqlite:'.$database);
            foreach (['users', 'password_reset_tokens', 'sessions'] as $table) {
                $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
                $pdo->exec($schema->sql);
                foreach (DB::table($table)->get() as $row) {
                    $values = (array) $row;
                    $statement = $pdo->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                    $statement->execute(array_values($values));
                }
            }
            $pdo = null;
            $start = microtime(true) + 1;
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/password-reset-worker.php')]);
                $process->setInput(json_encode(compact('database', 'payload', 'start'), JSON_THROW_ON_ERROR));
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $statuses[] = (int) $process->getOutput();
            }
            sort($statuses);
            $this->assertSame([200, 422], $statuses);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            @unlink($database);
            @unlink($database.'-wal');
            @unlink($database.'-shm');
        }
    }

    public function test_stateful_recovery_requires_csrf(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        foreach (['forgot-password', 'reset-password'] as $endpoint) {
            $this->withHeader('Origin', config('app.frontend_url'))->postJson('/api/auth/'.$endpoint, [])->assertStatus(419);
        }
    }
}
