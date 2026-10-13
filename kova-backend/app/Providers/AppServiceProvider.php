<?php

namespace App\Providers;

use App\Contracts\Billing\StripeBillingGateway;
use App\Contracts\Fiscal\PacProvider;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\StripeBillingGateway as StripeBillingGatewayDomain;
use App\Listeners\SyncStripePlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\StripeBillingService;
use App\Services\Fiscal\FinkokPacProvider;
use App\Tenancy\CurrentBranch;
use App\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use App\Services\Memberships\MembershipService;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
        $this->app->singleton(CurrentBranch::class);
        $this->app->bind(BillingGateway::class, StripeBillingGatewayDomain::class);

        $this->app->bind(
            StripeClient::class,
            function ($app, array $parameters = []): StripeClient {
                $config = $parameters['config'] ?? [
                    'api_key' => config('cashier.secret'),
                    'stripe_version' => Cashier::STRIPE_VERSION,
                    'api_base' => Cashier::$apiBaseUrl,
                ];

                return new StripeClient($config);
            }
        );

        $this->app->bind(StripeBillingGateway::class, StripeBillingService::class);
        $this->app->bind(PacProvider::class, FinkokPacProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            ! $this->app->environment('testing')
            && ! config('database.allow_destructive_commands', false),
        );

        $this->configureBillingRateLimiters();
        $this->configurePublicInvoiceRateLimiters();
        $this->configureStaffLoginRateLimiters();

        Cashier::useCustomerModel(Tenant::class);
        Event::listen(WebhookReceived::class, SyncStripePlanSubscription::class);
        Event::listen(WebhookHandled::class, SyncStripePlanSubscription::class);

        if (! $this->app->isProduction()) {
            return;
        }

        $requirements = [
            'APP_DEBUG=false' => config('app.debug') === false,
            'SESSION_DRIVER=database' => config('session.driver') === 'database',
            'SESSION_ENCRYPT=true' => config('session.encrypt') === true,
            'SESSION_SECURE_COOKIE=true' => config('session.secure') === true,
            'SESSION_HTTP_ONLY=true' => config('session.http_only') === true,
            'SESSION_SAME_SITE=lax' => config('session.same_site') === 'lax',
            'STRIPE_SECRET_KEY' => filled(config('cashier.secret')),
            'STRIPE_WEBHOOK_SECRET' => filled(config('cashier.webhook.secret')),
            'STRIPE_TAX_RATE_MX_IVA' => filled(config('billing.tax_rate')),
        ];

        if (config('fiscal.enabled')) {
            $requirements += [
                'FINKOK_USERNAME' => filled(config('fiscal.username')),
                'FINKOK_PASSWORD' => filled(config('fiscal.password')),
                'FISCAL_ENCRYPTION_KEY_V1' => filled(config('fiscal.encryption_keys.1')),
            ];
        }

        $invalid = array_keys(array_filter(
            $requirements,
            static fn (bool $valid): bool => ! $valid,
        ));

        if ($invalid !== []) {
            throw new RuntimeException(
                'Configuración insegura para producción: '.implode(', ', $invalid),
            );
        }
    }

    private function configureBillingRateLimiters(): void
    {
        RateLimiter::for(
            'billing-sensitive',
            fn (Request $request): Limit => $this->billingLimit($request, 5),
        );

        RateLimiter::for(
            'billing-standard',
            fn (Request $request): Limit => $this->billingLimit($request, 10),
        );
    }

    private function billingLimit(Request $request, int $attempts): Limit
    {
        $user = $request->user();
        $actor = $user
            ? get_class($user).':'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();

        $tenant = strtolower(trim((string) $request->header('X-Tenant-ID')));
        $route = $request->route()?->uri() ?? $request->path();

        return Limit::perMinute($attempts)
            ->by(implode('|', [$actor, $tenant ?: 'without-tenant', $route]))
            ->response(
                fn (Request $request, array $headers): JsonResponse => $this->rateLimitResponse(
                    $request,
                    $headers,
                ),
            );
    }

    private function configurePublicInvoiceRateLimiters(): void
    {
        RateLimiter::for(
            'public-invoice-read',
            fn (Request $request): array => $this->publicInvoiceLimits($request, 20, 60),
        );

        RateLimiter::for(
            'public-invoice-write',
            fn (Request $request): array => $this->publicInvoiceLimits($request, 5, 15),
        );

        RateLimiter::for(
            'public-invoice-resolve',
            fn (Request $request): Limit => Limit::perMinute(5)
                ->by($this->publicInvoiceOperationKey($request))
                ->response(
                    fn (Request $request, array $headers): JsonResponse => $this->rateLimitResponse(
                        $request,
                        $headers,
                    ),
                ),
        );
    }

    private function configureStaffLoginRateLimiters(): void
    {
        RateLimiter::for('staff-login-read', fn (Request $request): array => [
            Limit::perMinute(30)->by('staff-read|ip:'.$request->ip()),
            Limit::perMinute(20)->by('staff-read|code:'.hash('sha256', strtolower((string) $request->route('branchLoginCode')))),
        ]);

        RateLimiter::for('staff-login', function (Request $request): array {
            $code = strtolower(trim((string) $request->route('branchLoginCode')));
            $username = MembershipService::normalizeLoginName((string) $request->input('username'));
            $response = fn (Request $request, array $headers): JsonResponse => $this->rateLimitResponse($request, $headers);

            return [
                Limit::perMinute(20)->by('staff-login|ip:'.$request->ip())->response($response),
                Limit::perMinute(5)->by('staff-login|'.hash('sha256', $code.'|'.$username))->response($response),
            ];
        });
    }

    /**
     * Limit each public operation by its token and keep a broader IP ceiling so
     * rotating invalid tokens cannot bypass abuse protection.
     *
     * @return array<int, Limit>
     */
    private function publicInvoiceLimits(
        Request $request,
        int $tokenAttempts,
        int $ipAttempts,
    ): array {
        $operationKey = $this->publicInvoiceOperationKey($request);
        $tokenHash = hash('sha256', (string) $request->route('token'));

        $response = fn (Request $request, array $headers): JsonResponse => $this->rateLimitResponse(
            $request,
            $headers,
        );

        return [
            Limit::perMinute($tokenAttempts)
                ->by($operationKey.'|token:'.$tokenHash)
                ->response($response),
            Limit::perMinute($ipAttempts)
                ->by($operationKey.'|all-tokens')
                ->response($response),
        ];
    }

    private function publicInvoiceOperationKey(Request $request): string
    {
        $route = $request->route()?->uri() ?? $request->path();

        return implode('|', [
            'public-invoice',
            'ip:'.$request->ip(),
            $request->method(),
            $route,
        ]);
    }

    /**
     * @param  array<string, string|int>  $headers
     */
    private function rateLimitResponse(Request $request, array $headers): JsonResponse
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
        $requestId = trim((string) $request->attributes->get('request_id'));

        $payload = [
            'message' => 'Has realizado varias solicitudes. Intenta nuevamente en unos segundos.',
            'code' => 'rate_limit_exceeded',
            'retry_after' => $retryAfter,
        ];

        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        return response()->json($payload, 429, $headers);
    }
}
