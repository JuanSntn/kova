<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\StripeClient;

final class ConfigureBillingPortal extends Command
{
    protected $signature = 'billing:configure-test-portal';

    protected $description = 'Crea un portal de prueba limitado a datos de pago e historial, sin cambios de contrato';

    public function handle(StripeClient $stripe): int
    {
        if (config('billing.livemode') || ! str_starts_with((string) config('cashier.secret'), 'sk_test_')) {
            $this->error('Requiere Stripe de prueba.');

            return self::FAILURE;
        }
        $result = $stripe->billingPortal->configurations->create(['business_profile' => ['headline' => 'Facturación Kova'], 'features' => ['payment_method_update' => ['enabled' => true], 'invoice_history' => ['enabled' => true], 'customer_update' => ['enabled' => true, 'allowed_updates' => ['address', 'email', 'name', 'phone', 'tax_id']], 'subscription_cancel' => ['enabled' => false], 'subscription_update' => ['enabled' => false]]], ['idempotency_key' => 'kova-versioned-portal-test-v1']);
        $this->info('STRIPE_PORTAL_CONFIGURATION='.$result->id);

        return self::SUCCESS;
    }
}
