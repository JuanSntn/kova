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
