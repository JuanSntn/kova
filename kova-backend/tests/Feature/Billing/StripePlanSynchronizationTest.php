<?php

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\BillingPriceMapping;
use App\Models\Branch;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionOveragePolicy;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsagePeriod;
use App\Models\User;
use App\Services\Fiscal\OveragePolicyService;
use App\Services\Fiscal\StampCapacityService;
use App\Services\Fiscal\StampUsageSummaryService;
use App\Services\Subscriptions\BillingAccessPolicy;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\StripeContractSynchronizer;
use App\Services\Subscriptions\StripeWebhookProcessor;
use App\Services\Subscriptions\UsageService;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stripe\Price;
use Stripe\Subscription;
use Stripe\SubscriptionItem;
use Tests\TestCase;

class StripePlanSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CatalogService $catalog;

    private ContractService $contracts;

    private EntitlementService $access;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-01-31 12:00:00 UTC');
        now()->setTestNow('2026-01-31 12:00:00 UTC');
        $this->seed(PlanSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active']);
        $this->tenant->forceFill(['stripe_id' => 'cus_test'])->save();
        app(CurrentTenant::class)->set($this->tenant);
        $this->catalog = app(CatalogService::class);
        $this->contracts = app(ContractService::class);
        $this->access = app(EntitlementService::class);
        $this->contracts->create($this->tenant, $this->catalog->price('free'), true);
        foreach (PlanPrice::all() as $p) {
            BillingPriceMapping::create(['plan_price_id' => $p->id, 'stripe_price_id' => 'price_plan_'.$p->id]);
        }
        foreach (AddonPrice::all() as $p) {
            BillingPriceMapping::create(['addon_price_id' => $p->id, 'stripe_price_id' => 'price_addon_'.$p->id, 'stripe_meter_id' => 'meter_'.$p->id, 'meter_event_name' => 'stamps_'.$p->id]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function remote(PlanSubscription $s, string $role = 'base', string $status = 'active', array $extra = []): Subscription
    {
        $p = $role === 'base' ? $s->price : $s->addons()->first()->price;

        return Subscription::constructFrom(array_replace_recursive([
            'id' => 'sub_'.$role.'_'.$s->id, 'customer' => 'cus_test', 'status' => $status,
            'start_date' => now()->timestamp, 'billing_cycle_anchor' => now()->timestamp,
            'current_period_start' => now()->timestamp, 'current_period_end' => ($role === 'base' && $s->price->interval === 'year' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow())->timestamp,
            'cancel_at_period_end' => false, 'pending_update' => null, 'latest_invoice' => ['status' => 'paid'],
            'kova_debt_checked' => true, 'kova_unpaid_since' => null,
            'metadata' => ['contract_public_id' => $s->public_id, 'billing_role' => $role],
            'items' => ['data' => [['id' => 'si_'.$role, 'quantity' => 1, 'price' => ['id' => $this->catalog->mapping($p)->stripe_price_id, 'recurring' => ['usage_type' => $role === 'base' ? 'licensed' : 'metered']]]]],
        ], $extra));
    }

    private function paid(string $code = 'business', string $interval = 'month'): PlanSubscription
    {
        $s = $this->contracts->create($this->tenant, $this->catalog->price($code, $interval));
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($this->tenant, $this->remote($s));
        $sync->sync($this->tenant, $this->remote($s, 'extras'));

        return $s->refresh();
    }

    public function test_paid_access_waits_for_both_confirmed_billing_components(): void
    {
        $s = $this->contracts->create($this->tenant, $this->catalog->price('business'));
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($this->tenant, $this->remote($s));
        $this->assertSame('free', $this->access->current($this->tenant)->version->plan->code);
        $this->assertDatabaseCount('billing_outbox', 1);
        $sync->sync($this->tenant, $this->remote($s, 'extras'));
        $sync->sync($this->tenant, $this->remote($s, 'extras'));
        $this->assertSame($s->id, $this->access->current($this->tenant)->id);
        $this->assertSame(1, PlanSubscription::whereNotNull('current_tenant_id')->count());
        $this->assertSame(2, $s->billingLinks()->count());
    }

    public function test_unpaid_initial_checkout_does_not_activate_or_provision_extras(): void
    {
        $s = $this->contracts->create($this->tenant, $this->catalog->price('business'));
        app(StripeContractSynchronizer::class)->sync($this->tenant, $this->remote($s, 'base', 'incomplete', ['latest_invoice' => ['status' => 'open']]));
        $this->assertSame('free', $this->access->current($this->tenant)->version->plan->code);
        $this->assertDatabaseCount('billing_outbox', 0);
    }

    public function test_new_catalog_version_does_not_change_current_or_renewed_contract(): void
    {
        $s = $this->paid();
        $benefits = $this->access->forVersion($s->version);
        $benefits['max_products'] = 12;
        $v = $this->catalog->createVersion($s->version->plan, ['entitlements' => $benefits, 'prices' => ['month' => 20000], 'addons' => $s->version->availableAddons->map(fn ($a) => ['version' => $a->public_id, 'automatic' => (bool) $a->pivot->automatic])->all()]);
        $this->catalog->publish($v);
        $this->catalog->retire($s->version);
        app(StripeContractSynchronizer::class)->sync($this->tenant, $this->remote($s));
        $this->assertSame(150, $this->access->resolve($s->refresh())['max_products']);
        $this->assertSame(12900, $s->price->amount);
        $this->assertSame($v->id, $this->catalog->price('business')->plan_version_id);
    }

    public function test_published_prices_and_entitlements_cannot_be_edited(): void
    {
        $price = $this->catalog->price('business');
        try {
            $price->update(['amount' => 1]);
            $this->fail('Mutable price');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        $price->version->entitlements()->first()->update(['value' => 999]);
    }

    public function test_retirement_preserves_existing_addon_price(): void
    {
        $s = $this->paid();
        $a = $s->addons()->first();
        $this->catalog->retire($a->version);
        app(StripeContractSynchronizer::class)->sync($this->tenant, $this->remote($s, 'extras'));
        $this->assertSame(112, $a->refresh()->price->amount);
    }

    public function test_monthly_quota_uses_original_day_even_on_annual_contract(): void
    {
        $s = $this->paid('business', 'year');
        $periods = app(ConsumptionPeriod::class);
        $p = $periods->for($s, CarbonImmutable::parse('2026-03-01 00:00:00 UTC'));
        $this->assertSame('2026-02-28 12:00:00', $p['starts_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 12:00:00', $p['ends_at']->format('Y-m-d H:i:s'));
        $s->update(['usage_anchor_at' => '2024-01-31 12:00:00']);
        $p = $periods->for($s, CarbonImmutable::parse('2024-02-29 13:00:00 UTC'));
        $this->assertSame('2024-02-29 12:00:00', $p['starts_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-31 12:00:00', $p['ends_at']->format('Y-m-d H:i:s'));
    }

    public function test_stamp_excess_is_recorded_and_enqueued_once_and_resets_monthly(): void
    {
        $s = $this->paid('business', 'year');
        $addon = $s->addons()->firstOrFail();
        SubscriptionOveragePolicy::query()->create([
            'plan_subscription_id' => $s->id,
            'feature_id' => Feature::query()->where('code', 'included_stamps')->value('id'),
            'subscription_addon_id' => $addon->id,
            'addon_price_id' => $addon->addon_price_id,
            'enabled' => true,
            'unit_amount' => 112,
            'tax_rate_basis_points' => 1600,
            'estimated_unit_total' => 130,
            'currency' => 'mxn',
            'effective_from' => now(),
        ]);
        $usage = app(UsageService::class);
        for ($i = 0; $i < 50; $i++) {
            $usage->recordIssuedStamp($this->tenant, (string) Str::uuid(), CarbonImmutable::now());
        }
        $uuid = (string) Str::uuid();
        $a = $usage->recordIssuedStamp($this->tenant, $uuid, CarbonImmutable::now());
        $b = $usage->recordIssuedStamp($this->tenant, $uuid, CarbonImmutable::now());
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, $a->billable_quantity);
        $this->assertSame(1, BillingOutbox::where('kind', 'meter')->count());
        $this->travelTo(Carbon::parse('2026-02-28 12:00:00 UTC'));
        CarbonImmutable::setTestNow('2026-02-28 12:00:00 UTC');
        $c = $usage->recordIssuedStamp($this->tenant, (string) Str::uuid(), CarbonImmutable::now());
        $this->assertSame(0, $c->billable_quantity);
        $this->assertDatabaseCount('usage_periods', 2);
    }

    public function test_stamp_overage_is_rejected_before_recording_when_consent_is_disabled(): void
    {
        $this->paid('business');
        $usage = app(UsageService::class);
        for ($i = 0; $i < 50; $i++) {
            $usage->recordIssuedStamp($this->tenant, (string) Str::uuid(), CarbonImmutable::now());
        }

        try {
            $usage->recordIssuedStamp($this->tenant, (string) Str::uuid(), CarbonImmutable::now());
            $this->fail('El timbre excedente no debía registrarse sin consentimiento.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('desactivados', $exception->getMessage());
        }

        $this->assertDatabaseCount('usage_events', 50);
        $this->assertSame(0, BillingOutbox::query()->where('kind', 'meter')->count());
    }

    public function test_overage_can_be_disabled_when_billing_is_unhealthy(): void
    {
        $subscription = $this->paid('business');
        $addon = $subscription->addons()->firstOrFail();
        $policy = SubscriptionOveragePolicy::query()->create([
            'plan_subscription_id' => $subscription->id,
            'feature_id' => Feature::query()->where('code', 'included_stamps')->value('id'),
            'subscription_addon_id' => $addon->id,
            'addon_price_id' => $addon->addon_price_id,
            'enabled' => true,
            'unit_amount' => 112,
            'tax_rate_basis_points' => 1600,
            'estimated_unit_total' => 130,
            'currency' => 'mxn',
            'effective_from' => now(),
        ]);
        $subscription->update(['status' => SubscriptionStatus::PastDue]);
        $user = User::factory()->create();
        $request = Request::create('/api/billing/stamp-overage', 'PUT');
        $request->setUserResolver(fn () => $user);

        $updated = app(OveragePolicyService::class)->set($this->tenant, $user, false, $request);

        $this->assertNotNull($updated);
        $this->assertFalse($policy->refresh()->enabled);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'billing.stamp_overage_changed',
            'auditable_public_id' => $policy->public_id,
        ]);
    }

    public function test_stamp_reservation_rejects_before_pac_and_confirms_overage_once(): void
    {
        $subscription = $this->paid('business');
        $feature = Feature::query()->where('code', 'included_stamps')->firstOrFail();
        $window = app(ConsumptionPeriod::class)->for($subscription);
        $period = UsagePeriod::query()->create([
            'plan_subscription_id' => $subscription->id,
            'feature_id' => $feature->id,
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'included' => 50,
            'consumed' => 50,
        ]);
        $invoice = $this->invoiceForUsage();

        try {
            app(StampCapacityService::class)->reserve($this->tenant, $invoice);
            $this->fail('La reserva excedente no debía llegar al PAC sin consentimiento.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('desactivados', $exception->getMessage());
        }
        $this->assertDatabaseCount('usage_reservations', 0);

        $addon = $subscription->addons()->firstOrFail();
        SubscriptionOveragePolicy::query()->create([
            'plan_subscription_id' => $subscription->id,
            'feature_id' => $feature->id,
            'subscription_addon_id' => $addon->id,
            'addon_price_id' => $addon->addon_price_id,
            'enabled' => true,
            'unit_amount' => 112,
            'tax_rate_basis_points' => 1600,
            'estimated_unit_total' => 130,
            'currency' => 'mxn',
            'effective_from' => now(),
        ]);
        $reservation = app(StampCapacityService::class)->reserve($this->tenant, $invoice);
        $uuid = (string) Str::uuid();
        $first = app(UsageService::class)->confirmIssuedStamp($invoice, $reservation, $uuid, CarbonImmutable::now());
        $second = app(UsageService::class)->confirmIssuedStamp($invoice, $reservation, $uuid, CarbonImmutable::now());

        $this->assertSame('overage', $reservation->usage_type);
        $this->assertSame(112, $reservation->unit_amount);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(51, $period->refresh()->consumed);
        $this->assertSame(1, BillingOutbox::query()->where('kind', 'meter')->count());
    }

    private function invoiceForUsage(): Invoice
    {
        $branch = Branch::query()->create([
            'code' => 'PRIN',
            'name' => 'Principal',
            'timezone' => 'America/Mexico_City',
            'is_active' => true,
            'billing_enabled' => true,
        ]);
        $order = Order::query()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'folio' => 'V-1',
            'order_type' => 'dine_in',
            'status' => 'paid',
            'subtotal' => 100,
            'tax_base_total' => 100,
            'tax_total' => 16,
            'total' => 116,
            'payment_method' => 'cash',
            'paid_at' => now(),
        ]);

        return Invoice::query()->create([
            'order_id' => $order->id,
            'provider' => 'finkok',
            'series' => 'A',
            'folio' => 1,
            'issuer_snapshot' => ['rfc' => 'EKU9003173C9'],
            'receiver_snapshot' => ['rfc' => 'XAXX010101000'],
            'subtotal' => 100,
            'tax_total' => 16,
            'total' => 116,
            'currency' => 'MXN',
            'status' => 'queued',
            'idempotency_key_hash' => hash('sha256', 'usage-invoice'),
        ]);
    }

    public function test_invalid_fiscal_identifier_does_not_record_usage(): void
    {
        $this->paid();
        try {
            app(UsageService::class)->recordIssuedStamp($this->tenant, 'failed-attempt', CarbonImmutable::now());
        } catch (\DomainException) {
        }
        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_impayment_grace_is_not_reset_and_other_component_payment_does_not_clear_it(): void
    {
        $s = $this->paid();
        $sync = app(StripeContractSynchronizer::class);
        $failed = $this->remote($s, 'extras', 'past_due');
        $sync->sync($this->tenant, $failed);
        $deadline = $s->refresh()->grace_ends_at;
        $this->travel(3)->days();
        $sync->sync($this->tenant, $failed);
        $sync->sync($this->tenant, $this->remote($s));
        $this->assertTrue($deadline->equalTo($s->refresh()->grace_ends_at));
        $this->travel(5)->days();
        try {
            $this->access->assertWritable($this->tenant);
            $this->fail('Expected read-only');
        } catch (\DomainException) {
        }
        $sync->sync($this->tenant, $this->remote($s, 'extras'));
        $this->assertNull($s->refresh()->grace_ends_at);
        $this->assertSame(SubscriptionStatus::Active, $s->status);
    }

    public function test_stripe_outage_defers_suspension_for_at_most_twenty_four_hours(): void
    {
        $subscription = $this->paid();
        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->subMinute(),
            'billing_verification_failed_since' => now(),
        ]);

        $this->access->assertWritable($this->tenant);
        $failedSince = $subscription->refresh()
            ->billing_verification_failed_since;

        $this->travel(12)->hours();
        app(BillingAccessPolicy::class)->recordVerification(
            $subscription->refresh(),
            false,
        );
        $this->assertTrue(
            $failedSince->equalTo(
                $subscription->refresh()
                    ->billing_verification_failed_since
            )
        );
        $this->access->assertWritable($this->tenant);

        $this->travel(13)->hours();
        app(BillingAccessPolicy::class)->applyExpiredGrace(
            $this->tenant
        );

        $this->assertSame(
            SubscriptionStatus::Suspended,
            $subscription->refresh()->status,
        );

        $this->expectException(\DomainException::class);
        $this->access->assertWritable($this->tenant);
    }

    public function test_cancelled_contract_cannot_be_restored_by_a_late_active_event(): void
    {
        $s = $this->paid();
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($this->tenant, $this->remote($s, 'base', 'canceled'));
        $sync->sync($this->tenant, $this->remote($s));
        $this->assertSame('free', $this->access->current($this->tenant)->version->plan->code);
        $this->assertNotNull($s->refresh()->ended_at);
        $this->assertSame(1, PlanSubscription::whereNotNull('current_tenant_id')->count());
    }

    public function test_cancelled_base_with_debt_still_enqueues_extras_closure_before_grace_return(): void
    {
        $subscription = $this->paid();
        $subscription->billingLinks()
            ->where('role', 'base')
            ->update(['past_due_since' => now()]);

        app(StripeContractSynchronizer::class)->sync(
            $this->tenant,
            $this->remote(
                $subscription,
                'base',
                'canceled'
            )
        );

        $this->assertSame(
            SubscriptionStatus::PastDue,
            $subscription->refresh()->status
        );
        $this->assertNull($subscription->ended_at);
        $this->assertSame(
            1,
            BillingOutbox::query()
                ->where('kind', 'close_extras')
                ->where(
                    'payload->contract_id',
                    $subscription->id
                )
                ->count()
        );
    }

    public function test_schedule_fixes_target_offer_without_changing_current_access(): void
    {
        $s = $this->paid();
        $target = $this->catalog->price('restaurant');
        $change = $this->contracts->schedule($s, $target);
        $this->assertSame($target->id, $change->target_plan_price_id);
        $this->assertTrue($change->effective_at->equalTo($s->current_period_ends_at));
        $this->assertSame('business', $this->access->current($this->tenant)->version->plan->code);
        $this->assertSame(1, BillingOutbox::where('kind', 'schedule_plan')->count());
    }

    public function test_confirmed_renewal_changes_contract_only_after_both_components_and_keeps_history(): void
    {
        $s = $this->paid();
        $target = $this->catalog->price('restaurant');
        $change = $this->contracts->schedule($s, $target);
        $base = $this->remote($s);
        $extras = $this->remote($s, 'extras');
        $at = $change->effective_at->copy();
        $this->travelTo($at);
        CarbonImmutable::setTestNow($at);
        $base->current_period_start = $at->timestamp;
        $base->current_period_end = $at->copy()->addMonthNoOverflow()->timestamp;
        $base->items->data[0]->price = Price::constructFrom(['id' => $this->catalog->mapping($target)->stripe_price_id]);
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($this->tenant, $base);
        $this->assertSame($s->id, $this->access->current($this->tenant)->id);
        $extras->current_period_start = $at->timestamp;
        $extras->current_period_end = $at->copy()->addMonthNoOverflow()->timestamp;
        $sync->sync($this->tenant, $extras);
        $current = $this->access->current($this->tenant);
        $this->assertNotSame($s->id, $current->id);
        $this->assertSame($target->id, $current->plan_price_id);
        $this->assertNotNull($s->refresh()->ended_at);
        $this->assertSame('completed', $change->refresh()->status);
        $this->assertSame(150, $this->access->resolve($current)['included_stamps']);
        $this->assertSame(1, PlanSubscription::whereNotNull('current_tenant_id')->count());
    }

    public function test_branch_addon_payment_pending_does_not_grant_capacity(): void
    {
        $s = $this->paid('restaurant');
        $price = AddonPrice::whereHas('version.addon', fn ($q) => $q->where('code', 'extra_branch'))->firstOrFail();
        $remote = $this->remote($s, 'extras');
        $remote->pending_update = ['expires_at' => now()->addDay()->timestamp];
        $remote->items->data[] = SubscriptionItem::constructFrom(['id' => 'si_branch', 'quantity' => 2, 'price' => ['id' => $this->catalog->mapping($price)->stripe_price_id]]);
        app(StripeContractSynchronizer::class)->sync($this->tenant, $remote);
        $this->assertSame(1, $this->access->resolve($s)['included_branches']);
        $remote->pending_update = null;
        app(StripeContractSynchronizer::class)->sync($this->tenant, $remote);
        $this->assertSame(3, $this->access->resolve($s)['included_branches']);
    }

    public function test_contract_records_cannot_reference_another_tenants_subscription(): void
    {
        $s = $this->paid();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'another', 'status' => 'active']);
        $addon = $s->addons()->first();
        $this->expectException(\LogicException::class);
        app(CurrentTenant::class)->run($other, fn () => SubscriptionAddon::create(['plan_subscription_id' => $s->id, 'addon_version_id' => $addon->addon_version_id, 'addon_price_id' => $addon->addon_price_id, 'quantity' => 1]));
    }

    public function test_business_can_purchase_branch_addon(): void
    {
        $s = $this->paid();
        $price = AddonPrice::whereHas('version.addon', fn ($q) => $q->where('code', 'extra_branch'))->firstOrFail();
        $operation = $this->contracts->add($s, $price, 1, 'test');

        $this->assertSame('add_addon', $operation->kind);
        $this->assertSame($s->id, $operation->payload['contract_id']);
        $this->assertSame($price->id, $operation->payload['addon_price_id']);
        $this->assertSame(1, $operation->payload['quantity']);
    }

    public function test_unmapped_paid_offer_cannot_be_purchased(): void
    {
        BillingPriceMapping::query()->delete();
        $this->expectException(\DomainException::class);
        $this->catalog->assertPurchasable($this->catalog->price('business'));
    }

    public function test_tenant_scope_hides_contracts_addons_usage_and_outbox(): void
    {
        $s = $this->paid();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(CurrentTenant::class)->run($other, function () {
            $this->assertSame(0, PlanSubscription::count());
            $this->assertSame(0, SubscriptionAddon::count());
            $this->assertSame(0, UsageEvent::count());
            $this->assertSame(0, BillingOutbox::count());
        });
    }

    public function test_source_extras_event_cannot_advance_an_unpaid_upgrade(): void
    {
        $source = $this->paid();
        $change = $this->contracts->requestPlanChange(
            $source,
            $this->catalog->price('restaurant')
        );
        $extras = $source->billingLinks()->where('role', 'extras')->firstOrFail();

        app(StripeContractSynchronizer::class)->sync(
            $this->tenant,
            $this->remote($source, 'extras')
        );

        $this->assertSame(
            $source->id,
            $extras->refresh()->plan_subscription_id
        );
        $this->assertNull($change->refresh()->target_plan_subscription_id);
    }

    public function test_unrelated_pending_update_expiration_cannot_cancel_current_upgrade(): void
    {
        $source = $this->paid();
        $change = $this->contracts->requestPlanChange(
            $source,
            $this->catalog->price('restaurant')
        );
        $operation = BillingOutbox::query()
            ->where('kind', 'upgrade_plan')
            ->where('payload->change_id', $change->id)
            ->firstOrFail();
        $operation->update([
            'status' => 'completed',
            'result' => [
                'status' => 'awaiting_payment',
                'invoice_id' => 'in_new_attempt',
            ],
        ]);
        $remote = $this->remote(
            $source,
            'base',
            'active',
            ['latest_invoice' => ['id' => 'in_old_attempt', 'status' => 'paid']]
        );

        (new \ReflectionMethod(
            StripeWebhookProcessor::class,
            'expirePendingPlanChange'
        ))->invoke(app(StripeWebhookProcessor::class), $source, $remote);

        $this->assertSame('processing', $change->refresh()->status);
    }

    public function test_pending_upgrade_exposes_its_hosted_payment_action(): void
    {
        $source = $this->paid();
        $change = $this->contracts->requestPlanChange(
            $source,
            $this->catalog->price('restaurant')
        );
        BillingOutbox::query()
            ->where('kind', 'upgrade_plan')
            ->where('payload->change_id', $change->id)
            ->firstOrFail()
            ->update([
                'status' => 'completed',
                'result' => [
                    'status' => 'awaiting_payment',
                    'invoice_id' => 'in_pending_upgrade',
                    'invoice_url' => 'https://invoice.stripe.test/upgrade',
                ],
            ]);

        $summary = $change->refresh()->publicSummary();

        $this->assertSame('awaiting_payment', $summary['payment_status']);
        $this->assertSame(
            'https://invoice.stripe.test/upgrade',
            $summary['invoice_url']
        );
        $this->assertArrayNotHasKey('invoice_id', $summary);
    }

    public function test_component_sync_does_not_clear_aggregate_verification_failure(): void
    {
        $source = $this->paid();
        $source->update([
            'billing_verification_failed_since' => now()->subHours(12),
        ]);
        $failureStartedAt = $source->billing_verification_failed_since->copy();

        app(StripeContractSynchronizer::class)->sync(
            $this->tenant,
            $this->remote($source)
        );

        $this->assertTrue(
            $failureStartedAt->equalTo(
                $source->refresh()->billing_verification_failed_since
            )
        );
    }

    public function test_immediate_paid_upgrade_keeps_current_period_consumption(): void
    {
        $source = $this->paid();
        $window = app(ConsumptionPeriod::class)->for($source);
        UsagePeriod::query()->create([
            'plan_subscription_id' => $source->id,
            'feature_id' => Feature::query()
                ->where('code', 'included_stamps')
                ->value('id'),
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'included' => 50,
            'consumed' => 10,
        ]);
        $targetPrice = $this->catalog->price('restaurant');
        $change = $this->contracts->requestPlanChange($source, $targetPrice);
        $base = $this->remote($source);
        $base->items->data[0]->price = Price::constructFrom([
            'id' => $this->catalog->mapping($targetPrice)->stripe_price_id,
        ]);
        $sync = app(StripeContractSynchronizer::class);
        $sync->sync($this->tenant, $base);
        $target = $change->refresh()->targetContract;
        $extras = $this->remote(
            $source,
            'extras',
            'active',
            ['metadata' => ['contract_public_id' => $target->public_id]]
        );
        $sync->sync($this->tenant, $extras);

        $this->assertSame($target->id, $this->access->current($this->tenant)->id);
        $summary = app(StampUsageSummaryService::class)->for($this->tenant);
        $this->assertSame(10, $summary['used']);
        $this->assertSame(150, $summary['included']);
    }

    public function test_incomplete_expired_initial_subscription_releases_pending_slot(): void
    {
        $free = $this->access->current($this->tenant);
        $pending = $this->contracts->create(
            $this->tenant,
            $this->catalog->price('business')
        );

        app(StripeContractSynchronizer::class)->sync(
            $this->tenant,
            $this->remote(
                $pending,
                'base',
                'incomplete_expired',
                ['latest_invoice' => ['status' => 'void']]
            )
        );

        $pending->refresh();
        $this->assertNull($pending->pending_tenant_id);
        $this->assertNotNull($pending->ended_at);
        $this->assertSame(SubscriptionStatus::Cancelled, $pending->status);
        $this->assertSame($free->id, $this->access->current($this->tenant)->id);
    }

    public function test_extras_cannot_close_while_overage_metering_is_pending(): void
    {
        $subscription = $this->paid();
        $window = app(ConsumptionPeriod::class)->for($subscription);
        $period = UsagePeriod::query()->create([
            'plan_subscription_id' => $subscription->id,
            'feature_id' => Feature::query()
                ->where('code', 'included_stamps')
                ->value('id'),
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'included' => 50,
            'consumed' => 51,
        ]);
        UsageEvent::query()->create([
            'usage_period_id' => $period->id,
            'source_id' => 'pending-final-meter',
            'quantity' => 1,
            'billable_quantity' => 1,
            'occurred_at' => now(),
            'sequence_number' => 51,
            'usage_type' => 'overage',
            'is_overage' => true,
            'metering_status' => 'pending',
        ]);
        $operation = $this->contracts->enqueue(
            $this->tenant,
            'close_extras',
            'close-with-pending-meter',
            ['contract_id' => $subscription->id]
        );

        try {
            app(BillingProcessor::class)->run($operation);
            $this->fail('EXTRAS no debe cerrarse antes de reportar el consumo.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString(
                'consumo fiscal pendiente',
                $exception->getMessage()
            );
        }

        $this->assertSame('pending', $operation->refresh()->status);
        $this->assertSame(
            'active',
            $subscription->billingLinks()
                ->where('role', 'extras')
                ->value('status')
        );
    }
}
