<?php

use App\Domain\Billing\Contracts\BillingGateway;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\Tenant;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\StripeContractSynchronizer;
use App\Services\Subscriptions\StripeStateReader;
use App\Services\Subscriptions\UsageService;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Explicit integration check: php tests/Support/stripe-smoke.php --test
// Uses an isolated SQLite copy and creates only Stripe test resources, cancelled in finally.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array('--test', $argv, true) || ! str_starts_with((string) config('cashier.secret'), 'sk_test_') || config('billing.livemode')) {
    throw new RuntimeException('Stripe test mode required.');
}
$original = config('database.connections.sqlite.database');
$temp = tempnam(sys_get_temp_dir(), 'kova-stripe-smoke-');
copy($original, $temp);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $temp, 'database.connections.sqlite.url' => null]);
DB::purge('sqlite');
$stripe = app(StripeClient::class);
$tag = Str::uuid()->toString();
$remoteIds = [];
$failed = false;
function verify(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    } echo 'PASS '.$message.PHP_EOL;
}
try {
    $tenant = Tenant::create(['name' => 'Kova architecture test '.$tag, 'slug' => 'smoke-'.$tag, 'status' => 'active']);
    app(CurrentTenant::class)->set($tenant);
    $customer = $stripe->customers->create(['name' => 'Kova TEST '.$tag, 'email' => 'kova-smoke@example.test', 'payment_method' => 'pm_card_visa', 'invoice_settings' => ['default_payment_method' => 'pm_card_visa'], 'metadata' => ['kova_smoke_test' => $tag]]);
    $tenant->forceFill(['stripe_id' => $customer->id])->save();
    $catalog = app(CatalogService::class);
    $contracts = app(ContractService::class);
    $sync = app(StripeContractSynchronizer::class);
    $processor = app(BillingProcessor::class);
    $reader = app(StripeStateReader::class);
    $s = $contracts->create($tenant, $catalog->price('restaurant', 'year'));
    $base = $stripe->subscriptions->create(['customer' => $customer->id, 'items' => [['price' => $catalog->mapping($s->price)->stripe_price_id]], 'default_tax_rates' => [config('billing.tax_rate')], 'payment_behavior' => 'error_if_incomplete', 'expand' => ['latest_invoice'], 'metadata' => ['contract_public_id' => $s->public_id, 'billing_role' => 'base', 'tenant_public_id' => $tenant->public_id, 'type' => 'default']]);
    $remoteIds[] = $base->id;
    $sync->sync($tenant, $reader->subscription($tenant, $base->id));
    $op = BillingOutbox::where('kind', 'provision_extras')->where('operation_key', $tenant->id.':provision:'.$s->public_id)->firstOrFail();
    $result = $processor->run($op);
    $remoteIds[] = $result['stripe_subscription_id'];
    $sync->sync($tenant, $reader->subscription($tenant, $result['stripe_subscription_id']));
    verify($s->refresh()->status->value === 'active', 'annual contract activates only after base and monthly extras exist');
    $links = $s->billingLinks()->get()->keyBy('role');
    verify($links['base']->period_ends_at->diffInDays($links['extras']->period_ends_at, true) > 250, 'annual base and monthly extras have distinct billing periods');
    $branch = AddonPrice::whereHas('version.addon', fn ($q) => $q->where('code', 'extra_branch'))->firstOrFail();
    $op = $contracts->add($s, $branch, 2, 'smoke-add');
    $processor->run($op);
    $sync->sync($tenant, $reader->subscription($tenant, $links['extras']->stripe_subscription_id));
    $addon = $s->addons()->where('addon_price_id', $branch->id)->where('status', 'active')->firstOrFail();
    verify($addon->quantity === 2, 'paid prorated addon increases capacity');
    $usage = app(UsageService::class);
    for ($i = 0; $i < 151; $i++) {
        $usage->recordIssuedStamp($tenant, (string) Str::uuid(), CarbonImmutable::now());
    }
    $meterOp = BillingOutbox::where('kind', 'meter')->sole();
    $meterResult = $processor->run($meterOp);
    verify(isset($meterResult['identifier']), 'only stamp excess is accepted by Stripe meter');
    verify($processor->run($meterOp) === $meterResult, 'meter retry returns the original result');

    $declined = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', ['customer' => $customer->id]);
    $stripe->subscriptions->update($links['extras']->stripe_subscription_id, ['default_payment_method' => $declined->id]);
    $failedPurchase = $contracts->add($s, $branch, 3, 'smoke-decline');
    $processor->run($failedPurchase);
    $remote = $reader->subscription($tenant, $links['extras']->stripe_subscription_id);
    $sync->sync($tenant, $remote);
    verify(app(EntitlementService::class)->resolve($s)['included_branches'] === 3, 'declined addon payment does not grant extra capacity');
    verify($s->refresh()->grace_ends_at !== null, 'failed extras invoice starts a grace period');
    $card = $customer->invoice_settings->default_payment_method;
    $stripe->subscriptions->update($remote->id, ['default_payment_method' => $card]);
    $stripe->invoices->pay($remote->latest_invoice->id, ['payment_method' => $card]);
    $sync->sync($tenant, $reader->subscription($tenant, $remote->id));
    $addon = $s->addons()->where('addon_price_id', $branch->id)->where('status', 'active')->firstOrFail();
    verify($addon->quantity === 3 && $s->refresh()->grace_ends_at === null, 'successful retry grants capacity and clears grace');

    $change = $contracts->reduce($s, $addon, 1, []);
    echo 'Reduction date '.$change->effective_at->toIso8601String().' current '.now()->toIso8601String().PHP_EOL;
    $op = BillingOutbox::where('operation_key', $tenant->id.':reduce:'.$change->public_id)->firstOrFail();
    $processor->run($op);
    verify($addon->refresh()->quantity === 3, 'scheduled reduction preserves current capacity');
    $processor->run($contracts->cancelChange($change));
    verify($change->refresh()->status === 'cancelled', 'scheduled reduction can be cancelled');
    $change = $contracts->schedule($s, $catalog->price('business', 'month'));
    $processor->run(BillingOutbox::where('operation_key', $tenant->id.':schedule:'.$change->public_id)->firstOrFail());
    verify($s->refresh()->version->plan->code === 'restaurant', 'plan change schedules both billing components without changing access now');
    $processor->run($contracts->cancelChange($change));
    $gateway = app(BillingGateway::class);
    $gateway->cancelSubscription($tenant);
    verify($s->refresh()->cancel_at_period_end, 'cancellation is confirmed on both components');
    $gateway->reactivateSubscription($tenant);
    verify(! $s->refresh()->cancel_at_period_end, 'reactivation restores both components');
    verify(str_starts_with($gateway->createPortal($tenant), 'https://billing.stripe.com/'), 'restricted payment portal can be opened');
    echo "ALL STRIPE TEST CHECKS PASSED\n";
} catch (Throwable $e) {
    $failed = true;
    echo 'FAIL '.$e->getMessage().PHP_EOL;
} finally {
    foreach (array_reverse($remoteIds) as $id) {
        try {
            $remote = $stripe->subscriptions->retrieve($id);
            if ($remote->schedule) {
                $stripe->subscriptionSchedules->release(is_string($remote->schedule) ? $remote->schedule : $remote->schedule->id);
            }
            if ($remote->status !== 'canceled') {
                $stripe->subscriptions->cancel($id, ['invoice_now' => false, 'prorate' => false]);
            }
        } catch (Throwable $e) {
            echo 'Cleanup requires review for '.$id.': '.$e->getMessage().PHP_EOL;
        }
    }
    DB::disconnect('sqlite');
    unlink($temp);
}

exit($failed ? 1 : 0);
