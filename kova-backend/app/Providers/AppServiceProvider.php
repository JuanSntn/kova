<?php

namespace App\Providers;

use App\Contracts\Billing\StripeBillingGateway;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\StripeBillingGateway as StripeBillingGatewayDomain;
use App\Domain\Billing\SyncStripeSubscriptionService;
use App\Listeners\SyncStripePlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\StripeBillingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
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
        $this->app->bind(BillingGateway::class, StripeBillingGatewayDomain::class);

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            return new StripeClient(config('cashier.secret'));
        });

        $this->app->bind(StripeBillingGateway::class, StripeBillingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Tenant::class);
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
            'STRIPE_PRICE_BUSINESS' => filled(config('billing.plans.business.price')),
            'STRIPE_PRICE_RESTAURANT' => filled(config('billing.plans.restaurant.price')),
            'STRIPE_PRICE_EXTRA_BRANCH' => filled(config('billing.extras.branch_price')),
            'STRIPE_PRICE_EXTRA_STAMP' => filled(config('billing.extras.stamp_price')),
            'STRIPE_METER_EXTRA_STAMPS' => filled(config('billing.extras.stamp_meter')),
            'STRIPE_TAX_RATE_MX_IVA' => filled(config('billing.tax_rate')),
        ];

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
}
