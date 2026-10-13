<?php

namespace Tests\Audit;

require_once __DIR__.'/../Feature/Billing/StripePlanSynchronizationTest.php';

use App\Models\Feature;
use App\Models\UsagePeriod;
use App\Services\Fiscal\StampUsageSummaryService;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\StripeContractSynchronizer;
use App\Services\Subscriptions\StripeWebhookProcessor;
use Stripe\Price;
use Tests\Feature\Billing\StripePlanSynchronizationTest;

/** Targeted audit reproductions; run explicitly, separate from the default suite. */
final class StripeLifecycleAuditTest extends StripePlanSynchronizationTest
{
    private function paidFixture()
    {
        return (new \ReflectionMethod(StripePlanSynchronizationTest::class, 'paid'))->invoke($this);
    }

    private function remoteFixture($subscription, $role = 'base', array $extra = [])
    {
        return (new \ReflectionMethod(StripePlanSynchronizationTest::class, 'remote'))->invoke(
            $this,
            $subscription,
            $role,
            'active',
            $extra,
        );
    }

    public function test_audit_extras_event_before_paid_upgrade_must_not_move_link(): void
    {
        $source = $this->paidFixture();
        $change = app(ContractService::class)->requestPlanChange($source, app(CatalogService::class)->price('restaurant'));
        $extrasId = $source->billingLinks()->where('role', 'extras')->value('id');
        app(StripeContractSynchronizer::class)->sync($source->tenant, $this->remoteFixture($source, 'extras'));
        $this->assertDatabaseHas('billing_subscription_links', ['id' => $extrasId, 'plan_subscription_id' => $source->id]);
        $this->assertNull($change->refresh()->target_plan_subscription_id);
    }

    public function test_audit_late_expiration_must_not_cancel_a_newer_upgrade(): void
    {
        $source = $this->paidFixture();
        $change = app(ContractService::class)->requestPlanChange($source, app(CatalogService::class)->price('restaurant'));
        $remote = $this->remoteFixture(
            $source,
            'base',
            ['latest_invoice' => ['id' => 'in_old_attempt', 'status' => 'paid']],
        );
        (new \ReflectionMethod(StripeWebhookProcessor::class, 'expirePendingPlanChange'))->invoke(
            app(StripeWebhookProcessor::class),
            $source,
            $remote,
        );
        $this->assertSame('processing', $change->refresh()->status, 'An uncorrelated expiration cannot terminate a newer purchase.');
    }

    public function test_audit_partial_verification_must_preserve_outage_start(): void
    {
        $source = $this->paidFixture();
        $source->update(['billing_verification_failed_since' => now()->subHours(12)]);
        $firstFailure = $source->billing_verification_failed_since->copy();
        // BASE is reachable; EXTRAS remains unavailable to the reconciliation run.
        app(StripeContractSynchronizer::class)->sync($source->tenant, $this->remoteFixture($source));
        $this->assertNotNull($source->refresh()->billing_verification_failed_since);
        $this->assertTrue($firstFailure->equalTo($source->billing_verification_failed_since));
    }

    public function test_audit_paid_upgrade_must_preserve_consumed_stamps_in_same_month(): void
    {
        $source = $this->paidFixture();
        $window = app(ConsumptionPeriod::class)->for($source);
        UsagePeriod::create([
            'plan_subscription_id' => $source->id,
            'feature_id' => Feature::where('code', 'included_stamps')->value('id'),
            'starts_at' => $window['starts_at'], 'ends_at' => $window['ends_at'],
            'included' => 15, 'consumed' => 10,
        ]);
        $targetPrice = app(CatalogService::class)->price('restaurant');
        $change = app(ContractService::class)->requestPlanChange($source, $targetPrice);
        $base = $this->remoteFixture($source);
        $base->items->data[0]->price = Price::constructFrom([
            'id' => app(CatalogService::class)->mapping($targetPrice)->stripe_price_id,
        ]);
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($source->tenant, $base);
        $target = $change->refresh()->targetContract;
        $extras = $this->remoteFixture(
            $source,
            'extras',
            ['metadata' => ['contract_public_id' => $target->public_id]],
        );
        $sync->sync($source->tenant, $extras);
        $this->assertSame($target->id, app(EntitlementService::class)->current($source->tenant)->id);
        $summary = app(StampUsageSummaryService::class)->for($source->tenant);
        $this->assertSame(10, $summary['used'], 'Keeping the anchor must also keep already consumed stamps.');
    }

    public function test_audit_initial_incomplete_expired_must_release_pending_slot(): void
    {
        $current = $this->paidFixture();
        // End this fixture to model a fresh paid purchase, independent of the old contract.
        $current->update(['current_tenant_id' => null, 'ended_at' => now()]);
        $pending = app(ContractService::class)->create($current->tenant, app(CatalogService::class)->price('business'));
        $remote = $this->remoteFixture($pending);
        $remote->status = 'incomplete_expired';
        $remote->latest_invoice->status = 'void';
        app(StripeContractSynchronizer::class)->sync($pending->tenant, $remote);
        $this->assertNull($pending->refresh()->pending_tenant_id, 'A terminal initial payment attempt must not block the next purchase.');
    }
}
