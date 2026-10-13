<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingGateway;
use App\Enums\SubscriptionStatus;
use App\Models\BillingOutbox;
use App\Models\Tenant;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\StripeContractSynchronizer;
use App\Services\Subscriptions\StripeStateReader;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/*
 * Real Stripe Test integration check.
 *
 * Usage:
 *   php tests/Support/stripe-subscription-lifecycle.php --test
 *
 * It rejects live keys, uses a copied SQLite database, tags every Stripe
 * resource, and deletes the dedicated test clock (and its test-only objects)
 * in finally. It must never be part of the normal PHPUnit suite.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    ! in_array('--test', $argv, true)
    || config('billing.livemode')
    || ! preg_match('/^(sk|rk)_test_/', (string) config('cashier.secret'))
) {
    throw new RuntimeException(
        'Esta prueba exige --test, BILLING_LIVEMODE=false y una clave Stripe test.'
    );
}

$scenario = 'all';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--scenario=')) {
        $scenario = substr($argument, strlen('--scenario='));
    }
}

if (! in_array($scenario, [
    'all',
    'checkout',
    'upgrade',
    'success-renewal',
    'cancellation',
    'failed-renewal',
], true)) {
    throw new RuntimeException('Escenario inválido. Usa all, checkout, upgrade, success-renewal, cancellation o failed-renewal.');
}

$sourceDatabase = (string) config('database.connections.sqlite.database');

if (config('database.default') !== 'sqlite' || ! is_file($sourceDatabase)) {
    throw new RuntimeException(
        'La prueba exige una base SQLite local existente para poder clonarla de forma aislada.'
    );
}

$temporaryDatabase = tempnam(sys_get_temp_dir(), 'kova-stripe-lifecycle-');

if ($temporaryDatabase === false || ! copy($sourceDatabase, $temporaryDatabase)) {
    throw new RuntimeException('No se pudo crear la base SQLite temporal de la prueba.');
}

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $temporaryDatabase,
    'database.connections.sqlite.url' => null,
]);

DB::purge('sqlite');

$stripe = app(StripeClient::class);
$tag = Str::uuid()->toString();
$clockId = null;
$customerIds = [];
$subscriptionIds = [];
$failed = false;

function lifecycleAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}".PHP_EOL;
}

function lifecycleSetTime(int $timestamp): void
{
    $time = CarbonImmutable::createFromTimestampUTC($timestamp);

    Carbon::setTestNow($time);
    CarbonImmutable::setTestNow($time);
}

function lifecycleAdvanceClock(StripeClient $stripe, string $clockId, int $target): int
{
    $clock = $stripe->testHelpers->testClocks->advance($clockId, [
        'frozen_time' => $target,
    ]);

    $deadline = microtime(true) + 50;

    do {
        if ($clock->status === 'ready') {
            lifecycleSetTime((int) $clock->frozen_time);

            return (int) $clock->frozen_time;
        }

        usleep(250000);
        $clock = $stripe->testHelpers->testClocks->retrieve($clockId);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('El reloj de prueba no terminó de avanzar en 50 segundos.');
}

function lifecyclePeriodEnd(object $subscription): int
{
    $periodEnd = data_get($subscription, 'current_period_end')
        ?? data_get($subscription, 'items.data.0.current_period_end');

    if (! is_int($periodEnd) && ! ctype_digit((string) $periodEnd)) {
        throw new RuntimeException('Stripe no devolvió un fin de periodo válido para la suscripción de prueba.');
    }

    return (int) $periodEnd;
}

function lifecycleRenewalTarget(object $subscription): int
{
    /*
     * Stripe first creates the renewal invoice at the period boundary, then
     * finalizes and attempts payment approximately an hour later. Advancing
     * beyond that second step is essential: a draft invoice is not a payment
     * outcome and must not be interpreted as one by Kova.
     */
    return lifecyclePeriodEnd($subscription) + 3700;
}

/**
 * Crea una compra ya pagada en Stripe Test y deja que Kova realice la
 * provisión separada de extras. Checkout se verifica en un escenario propio,
 * ya que Stripe no permite completar Checkout hospedado por API de servidor.
 */
function lifecycleActivatePaidContract(
    StripeClient $stripe,
    string $tag,
    string $clockId,
    string $suffix,
    array &$customerIds,
    array &$subscriptionIds,
): array {
    $tenant = Tenant::query()->create([
        'name' => "Kova lifecycle {$suffix} {$tag}",
        'slug' => 'lifecycle-'.Str::lower(Str::random(18)),
        'status' => 'active',
    ]);

    app(CurrentTenant::class)->set($tenant);

    $customer = $stripe->customers->create([
        'name' => "Kova lifecycle {$suffix}",
        'email' => "lifecycle-{$suffix}@example.test",
        'test_clock' => $clockId,
        'payment_method' => 'pm_card_visa',
        'invoice_settings' => [
            'default_payment_method' => 'pm_card_visa',
        ],
        'metadata' => [
            'kova_lifecycle_test' => $tag,
            'scenario' => $suffix,
        ],
    ]);
    $customerIds[] = $customer->id;

    $tenant->forceFill(['stripe_id' => $customer->id])->save();

    $catalog = app(CatalogService::class);
    $contracts = app(ContractService::class);
    $sync = app(StripeContractSynchronizer::class);
    $reader = app(StripeStateReader::class);
    $processor = app(BillingProcessor::class);

    $contract = $contracts->create($tenant, $catalog->price('business', 'month'));

    $base = $stripe->subscriptions->create([
        'customer' => $customer->id,
        'items' => [[
            'price' => $catalog->mapping($contract->price)->stripe_price_id,
        ]],
        'default_tax_rates' => [config('billing.tax_rate')],
        'payment_behavior' => 'error_if_incomplete',
        'expand' => ['latest_invoice'],
        'metadata' => [
            'contract_public_id' => $contract->public_id,
            'tenant_public_id' => $tenant->public_id,
            'billing_role' => 'base',
            'type' => 'default',
            'kova_lifecycle_test' => $tag,
        ],
    ]);
    $subscriptionIds[] = $base->id;

    $sync->sync($tenant, $reader->subscription($tenant, $base->id));

    $provisionExtras = BillingOutbox::query()
        ->where('kind', 'provision_extras')
        ->where('payload->contract_id', $contract->id)
        ->firstOrFail();
    $extras = $processor->run($provisionExtras);
    $subscriptionIds[] = $extras['stripe_subscription_id'];
    $sync->sync($tenant, $reader->subscription($tenant, $extras['stripe_subscription_id']));

    $contract->refresh();

    lifecycleAssert(
        $contract->status === SubscriptionStatus::Active
        && $contract->current_tenant_id === $tenant->id,
        "{$suffix}: compra confirmada en Stripe y contrato activado en Kova",
    );

    return [
        'tenant' => $tenant,
        'customer' => $customer,
        'contract' => $contract,
        'base_id' => $base->id,
        'extras_id' => $extras['stripe_subscription_id'],
    ];
}

function lifecycleVerifyFailedRenewal(
    StripeClient $stripe,
    string $tag,
    string $clockId,
    array &$customerIds,
    array &$subscriptionIds,
): void {
    $failedRenewal = lifecycleActivatePaidContract(
        $stripe,
        $tag,
        $clockId,
        'failed-renewal',
        $customerIds,
        $subscriptionIds,
    );
    $declined = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', [
        'customer' => $failedRenewal['customer']->id,
    ]);
    $stripe->customers->update($failedRenewal['customer']->id, [
        'invoice_settings' => ['default_payment_method' => $declined->id],
    ]);

    foreach ([$failedRenewal['base_id'], $failedRenewal['extras_id']] as $subscriptionId) {
        $stripe->subscriptions->update($subscriptionId, [
            'default_payment_method' => $declined->id,
        ]);
    }

    $failingBase = $stripe->subscriptions->retrieve($failedRenewal['base_id']);
    lifecycleAdvanceClock($stripe, $clockId, lifecycleRenewalTarget($failingBase));

    $reader = app(StripeStateReader::class);
    $sync = app(StripeContractSynchronizer::class);
    $remoteBase = $reader->subscription($failedRenewal['tenant'], $failedRenewal['base_id']);
    $sync->sync($failedRenewal['tenant'], $remoteBase);
    $sync->sync($failedRenewal['tenant'], $reader->subscription($failedRenewal['tenant'], $failedRenewal['extras_id']));

    $failedContract = $failedRenewal['contract']->refresh();

    echo 'Failed renewal state: stripe='.(string) $remoteBase->status
        .' invoice='.(string) data_get($remoteBase, 'latest_invoice.status')
        .' local='.$failedContract->status->value
        .' grace='.($failedContract->grace_ends_at ? 'set' : 'unset').PHP_EOL;

    lifecycleAssert(
        $failedContract->status === SubscriptionStatus::PastDue
        && $failedContract->grace_ends_at !== null,
        'Un cobro de renovación rechazado queda como past_due con gracia en la base de Kova',
    );
}

try {
    $clock = $stripe->testHelpers->testClocks->create([
        'frozen_time' => time(),
        'name' => 'Kova lifecycle '.$tag,
    ]);
    $clockId = $clock->id;
    lifecycleSetTime((int) $clock->frozen_time);

    /*
     * Checkout preparation: validates the actual Checkout Session created by
     * Kova while keeping the session uncompleted. A separate paid subscription
     * below exercises the post-payment synchronization path deterministically.
     */
    $checkoutTenant = Tenant::query()->create([
        'name' => "Kova checkout {$tag}",
        'slug' => 'checkout-'.Str::lower(Str::random(18)),
        'status' => 'active',
    ]);
    app(CurrentTenant::class)->set($checkoutTenant);

    $checkoutCustomer = $stripe->customers->create([
        'name' => 'Kova checkout lifecycle',
        'email' => 'checkout-lifecycle@example.test',
        'test_clock' => $clockId,
        'metadata' => ['kova_lifecycle_test' => $tag, 'scenario' => 'checkout'],
    ]);
    $customerIds[] = $checkoutCustomer->id;
    $checkoutTenant->forceFill(['stripe_id' => $checkoutCustomer->id])->save();

    $catalog = app(CatalogService::class);
    $contracts = app(ContractService::class);
    $processor = app(BillingProcessor::class);
    $reconciler = app(\App\Services\Subscriptions\BillingReconciler::class);
    $checkoutContract = $contracts->create($checkoutTenant, $catalog->price('business', 'month'));
    $checkoutOperation = $contracts->enqueue($checkoutTenant, 'checkout', 'lifecycle-checkout', [
        'contract_id' => $checkoutContract->id,
        'email' => 'checkout-lifecycle@example.test',
    ]);
    $checkoutResult = $processor->run($checkoutOperation);
    $checkoutSession = $stripe->checkout->sessions->retrieve($checkoutResult['session_id']);

    lifecycleAssert(
        $checkoutSession->status === 'open'
        && $checkoutSession->customer === $checkoutCustomer->id
        && data_get($checkoutSession, 'metadata.contract_public_id') === $checkoutContract->public_id,
        'Checkout real queda asociado sólo con su contrato y cliente de prueba',
    );

    $stripe->checkout->sessions->expire($checkoutSession->id);
    $reconciler->cancelPendingCheckout($checkoutTenant);
    lifecycleAssert(
        $checkoutContract->refresh()->ended_at !== null,
        'Cancelar un Checkout abierto libera el contrato pendiente en Kova',
    );

    if ($scenario === 'failed-renewal') {
        lifecycleVerifyFailedRenewal($stripe, $tag, $clockId, $customerIds, $subscriptionIds);
    } elseif ($scenario !== 'checkout') {
        $happy = lifecycleActivatePaidContract(
            $stripe,
            $tag,
            $clockId,
            'happy',
            $customerIds,
            $subscriptionIds,
        );

        $gateway = app(BillingGateway::class);
        $gateway->cancelSubscription($happy['tenant']);
        $afterFirstCancellation = $happy['contract']->refresh();

        lifecycleAssert(
            $afterFirstCancellation->cancel_at_period_end,
            'La primera cancelación queda programada localmente',
        );

        $remoteBase = $stripe->subscriptions->retrieve($happy['base_id']);
        lifecycleAssert(
            $remoteBase->cancel_at_period_end === true,
            'La primera cancelación queda programada en Stripe Test',
        );

        $gateway->reactivateSubscription($happy['tenant']);
        lifecycleAssert(
            ! $happy['contract']->refresh()->cancel_at_period_end,
            'La reactivación revierte la primera cancelación sin crear otra suscripción',
        );

        $change = app(ContractService::class)->requestPlanChange(
            $happy['contract']->refresh(),
            app(CatalogService::class)->price('restaurant', 'month'),
            operationId: 'lifecycle-upgrade',
        );
        $upgrade = BillingOutbox::query()
            ->where('kind', 'upgrade_plan')
            ->where('payload->change_id', $change->id)
            ->firstOrFail();
        app(BillingProcessor::class)->run($upgrade);

        $upgraded = app(EntitlementService::class)->current($happy['tenant']);

        lifecycleAssert(
            $upgraded !== null
            && $upgraded->version->plan->code === 'restaurant'
            && $change->refresh()->status === 'completed',
            'El cambio de plan se confirma en Stripe y reemplaza el contrato vigente en Kova',
        );

        $upgradedLinks = $upgraded->billingLinks()->get()->keyBy('role');
        $subscriptionIds[] = $upgradedLinks['base']->stripe_subscription_id;
        $subscriptionIds[] = $upgradedLinks['extras']->stripe_subscription_id;
        $reader = app(StripeStateReader::class);
        $sync = app(StripeContractSynchronizer::class);

        if (in_array($scenario, ['all', 'success-renewal'], true)) {
            /* Renewal with a successful payment. */
            $baseForRenewal = $stripe->subscriptions->retrieve($upgradedLinks['base']->stripe_subscription_id);
            $periodEndBeforeRenewal = lifecyclePeriodEnd($baseForRenewal);
            lifecycleAdvanceClock($stripe, $clockId, lifecycleRenewalTarget($baseForRenewal));
            $renewedBase = $reader->subscription($happy['tenant'], $upgradedLinks['base']->stripe_subscription_id);
            $sync->sync($happy['tenant'], $renewedBase);
            $sync->sync($happy['tenant'], $reader->subscription($happy['tenant'], $upgradedLinks['extras']->stripe_subscription_id));

            lifecycleAssert(
                app(EntitlementService::class)->current($happy['tenant'])?->status === SubscriptionStatus::Active
                && $renewedBase->status === 'active'
                && data_get($renewedBase, 'latest_invoice.status') === 'paid'
                && lifecyclePeriodEnd($renewedBase) > $periodEndBeforeRenewal,
                'Al avanzar el reloj, una renovación pagada queda confirmada en Stripe y conserva el contrato activo en Kova',
            );
        }

        if (in_array($scenario, ['all', 'cancellation'], true)) {
            /* Second cancellation, then advance through its period end. */
            $gateway->cancelSubscription($happy['tenant']);
            lifecycleAssert(
                $upgraded->refresh()->cancel_at_period_end,
                'La segunda cancelación queda programada en el contrato cambiado',
            );

            $baseForEnd = $stripe->subscriptions->retrieve($upgradedLinks['base']->stripe_subscription_id);
            lifecycleAdvanceClock($stripe, $clockId, lifecycleRenewalTarget($baseForEnd));
            $sync->sync($happy['tenant'], $reader->subscription($happy['tenant'], $upgradedLinks['base']->stripe_subscription_id));
            $closeExtras = BillingOutbox::query()
                ->where('kind', 'close_extras')
                ->where('payload->contract_id', $upgraded->id)
                ->firstOrFail();
            app(BillingProcessor::class)->run($closeExtras);

            lifecycleAssert(
                $upgraded->refresh()->status === SubscriptionStatus::Cancelled
                && app(EntitlementService::class)->current($happy['tenant'])?->version->plan->code === 'free',
                'Al terminar el periodo cancelado, Kova conserva el historial y vuelve al contrato gratuito',
            );
        }

        if ($scenario === 'all') {
            lifecycleVerifyFailedRenewal($stripe, $tag, $clockId, $customerIds, $subscriptionIds);
        }
    }

    echo 'ALL STRIPE SUBSCRIPTION LIFECYCLE CHECKS PASSED'.PHP_EOL;
} catch (Throwable $exception) {
    $failed = true;
    echo 'FAIL '.$exception->getMessage().PHP_EOL;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();

    foreach (array_unique(array_reverse($subscriptionIds)) as $subscriptionId) {
        try {
            $remote = $stripe->subscriptions->retrieve($subscriptionId);

            if ($remote->status !== 'canceled') {
                $stripe->subscriptions->cancel($remote->id, [
                    'invoice_now' => false,
                    'prorate' => false,
                ]);
            }
        } catch (Throwable $exception) {
            echo "Cleanup requires review for subscription {$subscriptionId}: {$exception->getMessage()}".PHP_EOL;
        }
    }

    foreach (array_unique(array_reverse($customerIds)) as $customerId) {
        try {
            $stripe->customers->delete($customerId);
        } catch (Throwable $exception) {
            echo "Cleanup requires review for customer {$customerId}: {$exception->getMessage()}".PHP_EOL;
        }
    }

    if ($clockId) {
        try {
            $stripe->testHelpers->testClocks->delete($clockId);
        } catch (Throwable $exception) {
            echo "Cleanup requires review for test clock {$clockId}: {$exception->getMessage()}".PHP_EOL;
        }
    }

    DB::disconnect('sqlite');

    if (is_file($temporaryDatabase)) {
        unlink($temporaryDatabase);
    }
}

exit($failed ? 1 : 0);
