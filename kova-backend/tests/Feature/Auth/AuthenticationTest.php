<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_unverified_owner_and_tenant_on_the_free_plan(): void
    {
        Notification::fake();
        $this->seed(DatabaseSeeder::class);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan Carlos',
            'business_name' => 'Café Central',
            'email' => 'JUAN@EXAMPLE.COM',
            'password' => 'KovaPass1',
            'password_confirmation' => 'KovaPass1',
            'email_verified_at' => now()->toISOString(),
            'verified' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('email', 'juan@example.com')
            ->assertJsonPath('tenant.name', 'Café Central');

        $user = User::query()->where('email', 'juan@example.com')->sole();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertGuest();
        $this->assertDatabaseHas('memberships', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('plan_subscriptions', [
            'monthly_price_snapshot' => 0,
            'status' => 'active',
        ]);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_an_unverified_user_cannot_log_in(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'KovaPass1',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'KovaPass1',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->assertGuest();
    }

    public function test_a_verified_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'password' => 'KovaPass1',
        ]);

        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'KovaPass1',
                'remember' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.id');

        $this->assertAuthenticatedAs($user);

        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Origin', config('app.frontend_url'))
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_a_valid_signed_link_verifies_the_email_and_redirects_to_the_frontend(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->get($url)
            ->assertRedirect(config('app.frontend_url').'/email-verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_invalid_or_tampered_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            [
                'id' => $user->id,
                'hash' => 'invalid-hash',
            ],
        );

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_user_can_request_another_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/auth/email/verification-notification', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    private function pendingSession(User $user): array
    {
        return ['email_verification' => [
            'user_id' => $user->id,
            'credential_fingerprint' => $user->verificationCredentialFingerprint(),
            'email' => $user->email,
            'expires_at' => now()->addHour()->timestamp,
        ]];
    }

    public function test_status_requires_server_session_proof_and_ignores_client_claims(): void
    {
        $user = User::factory()->unverified()->create();
        $this->getJson('/api/auth/email/verification-status?email='.$user->email.'&verified=true')
            ->assertOk()->assertExactJson(['status' => 'unavailable'])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($user))
            ->getJson('/api/auth/email/verification-status')
            ->assertOk()->assertJsonPath('status', 'pending');
        $user->markEmailAsVerified();
        $this->getJson('/api/auth/email/verification-status')
            ->assertOk()->assertJsonPath('status', 'verified');
        $this->assertGuest();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_status_cannot_read_another_account_and_expires(): void
    {
        $pending = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($pending))
            ->getJson('/api/auth/email/verification-status?email='.$other->email.'&user_id='.$other->id)
            ->assertJsonPath('email', $pending->email)->assertJsonPath('status', 'pending');
        $this->travel(61)->minutes();
        $this->getJson('/api/auth/email/verification-status')
            ->assertExactJson(['status' => 'unavailable']);
    }

    public function test_correct_password_establishes_pending_context_without_authenticating(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'KovaPass1']);
        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'KovaPass1'])
            ->assertForbidden()->assertSessionHas('email_verification.user_id', $user->id);
        $this->getJson('/api/auth/email/verification-status')->assertJsonPath('status', 'pending');
        $this->assertGuest();
    }

    public function test_polling_does_not_exhaust_the_resend_rate_limit(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($user));
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/auth/email/verification-status')->assertOk();
        }
        $this->postJson('/api/auth/email/verification-notification', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_wrong_password_does_not_establish_pending_context(): void
    {
        $user = User::factory()->unverified()->create();
        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertSessionMissing('email_verification');
    }

    public function test_known_account_resend_has_honest_delivery_feedback_and_server_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($user))
            ->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertOk()->assertJsonPath('delivery', 'local');
        $this->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertStatus(429)->assertHeader('Retry-After', '60');
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertOk();
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);
    }

    public function test_resend_failure_is_not_reported_as_success_to_known_account(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $user = User::factory()->unverified()->create();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($user))
            ->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertStatus(503)->assertJsonPath('delivery', 'failed');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_registration_delivery_failure_preserves_account_and_allows_retry(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->seed(DatabaseSeeder::class);
        $this->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/register', [
                'name' => 'Owner', 'business_name' => 'Business', 'email' => 'owner@example.com',
                'password' => 'KovaPass1', 'password_confirmation' => 'KovaPass1',
            ])->assertCreated()->assertJsonPath('delivery', 'failed')->assertSessionHas('email_verification');
        $user = User::where('email', 'owner@example.com')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertGuest();
        Notification::fake();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/email/verification-notification', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_repeated_valid_link_does_not_emit_verified_twice(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Illuminate\Auth\Events\Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
        $this->get($url)->assertRedirect();
        $this->get($url)->assertRedirect();
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Illuminate\Auth\Events\Verified::class, 1);
        $this->assertGuest();
    }

    public function test_smtp_submission_and_already_verified_feedback_for_proven_account(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = User::factory()->unverified()->create();
        $this->withHeader('Origin', config('app.frontend_url'))->withSession($this->pendingSession($user))
            ->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertOk()->assertJsonPath('delivery', 'submitted');
        $user->markEmailAsVerified();
        $this->postJson('/api/auth/email/verification-notification', ['email' => $user->email])
            ->assertOk()->assertJsonPath('status', 'verified');
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
    }

    public function test_public_resend_does_not_disclose_missing_or_verified_accounts(): void
    {
        Notification::fake();
        $verified = User::factory()->create();
        $missing = $this->postJson('/api/auth/email/verification-notification', ['email' => 'missing@example.com'])->assertOk()->json();
        $this->postJson('/api/auth/email/verification-notification', ['email' => $verified->email])
            ->assertOk()->assertExactJson($missing);
        Notification::assertNothingSent();
    }

    public function test_unverified_authenticated_user_cannot_access_protected_routes(): void
    {
        $this->actingAs(User::factory()->unverified()->create());
        foreach (['/api/auth/me', '/api/navigation', '/api/billing'] as $path) {
            $this->withHeader('X-Tenant-ID', 'fake-tenant')->getJson($path.'?email_verified_at=2026-01-01&verified=true')->assertForbidden();
        }
    }

    public function test_expired_and_modified_signatures_do_not_verify_account(): void
    {
        $user = User::factory()->unverified()->create();
        $parameters = ['id' => $user->id, 'hash' => sha1($user->email)];
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), $parameters);
        $this->get($expired)->assertForbidden();
        $valid = URL::temporarySignedRoute('verification.verify', now()->addHour(), $parameters);
        $this->get($valid.'&verified=true')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_security_headers_are_present_without_exposing_debug_details(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_a_stateful_login_without_a_csrf_token_is_rejected(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->withMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', config('app.frontend_url'))
            ->postJson('/api/auth/login', [
                'email' => 'verified@example.com',
                'password' => 'KovaPass1',
            ])
            ->assertStatus(419);
    }
}
