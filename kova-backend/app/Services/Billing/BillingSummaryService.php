<?php

namespace App\Services\Billing;

use App\Contracts\Billing\StripeBillingGateway;
use App\Data\Billing\BillingSummaryData;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\SubscriptionChange;
use App\Models\Tenant;
use App\Models\UsagePeriod;
use App\Models\User;
use App\Services\Fiscal\StampUsageSummaryService;
use App\Services\Subscriptions\BillingReconciler;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ConsumptionPeriod;
use App\Services\Subscriptions\EntitlementService;
use Illuminate\Support\Facades\DB;

final class BillingSummaryService
{
    public function __construct(private StripeBillingGateway $stripeBilling, private CatalogService $catalog, private EntitlementService $access, private ConsumptionPeriod $periods, private BillingReconciler $reconciler, private StampUsageSummaryService $stampUsage) {}

    public function build(Tenant $tenant, ?User $user = null): BillingSummaryData
    {
        $s = $this->access->current($tenant);
        $snapshot = $this->stripeBilling->getSnapshot($tenant);
        $period = $s ? $this->periods->for($s) : null;
        $benefits = $s ? $this->access->resolve($s) : [];
        $subscription = $s && $s->price->amount > 0 ? [
            'id' => $s->public_id, 'plan' => $s->version->plan->code, 'plan_name' => $s->version->name, 'plan_price' => $this->catalog->decimal($s->price->amount),
            'status' => $s->status->value, 'current_period_ends_at' => $s->current_period_ends_at, 'cancel_at_period_end' => $s->cancel_at_period_end,
            'past_due' => in_array($s->status->value, ['past_due', 'suspended']), 'ended_at' => $s->ended_at,
            'version' => $s->version->public_id, 'offer_id' => $s->price->public_id, 'amount' => $s->price->amount, 'currency' => $s->price->currency, 'interval' => $s->price->interval,
            'grace_ends_at' => $s->grace_ends_at,
        ] : null;
        $details = $snapshot->customerExists ? array_merge(['name' => $tenant->name, 'email' => $user?->email, 'phone' => null, 'address' => null], $snapshot->billingDetails ?? []) : null;

        return new BillingSummaryData(
            currentPlan: $s?->version?->plan?->code ?? 'free', currentPlanName: $s?->version?->name ?? 'Free',
            currentSubscription: $subscription, paymentMethod: $snapshot->paymentMethod, billingDetails: $details,
            actions: ['can_manage_portal' => $user?->can('billing.manage') ?? false, 'can_manage' => $user?->can('billing.manage') ?? false],
            stripe: ['customer_exists' => $snapshot->customerExists, 'subscription_exists' => $s ? $s->billingLinks()->exists() : false],
            plans: $this->catalog->offers(),
            commercial: [
                'capacity_usage' => [
                    'max_products' => $tenant->products()->count(),
                    'max_users' => $tenant->memberships()->where('status', 'active')->count(),
                    'included_branches' => $tenant->branches()->where('is_active', true)->where('billing_enabled', true)->count(),
                ],
                'monthly_sales_consumed' => $period ? (int) $tenant->orders()->where('status', 'paid')->where('paid_at', '>=', $period['starts_at'])->where('paid_at', '<', $period['ends_at'])->sum(DB::raw('ROUND(total * 100)')) : 0,
                'contract' => $s ? ['id' => $s->public_id, 'version' => $s->version->public_id, 'offer_id' => $s->price->public_id, 'amount' => $s->price->amount, 'interval' => $s->price->interval, 'currency' => $s->price->currency, 'status' => $s->status->value] : null,
                'branches' => $tenant->branches()->where('is_active', true)->where('billing_enabled', true)->get()->map(fn ($b) => ['id' => $b->public_id, 'name' => $b->name])->all(),
                'active_memberships' => $tenant->memberships()->where('status', 'active')->with('user')->get()->map(function ($membership) {
                    $membership->user->unsetRelation('roles');
                    return [
                        'id' => $membership->public_id,
                        'name' => $membership->user->name,
                        'username' => $membership->login_name,
                        'is_owner' => $membership->user->hasRole('owner'),
                    ];
                })->all(),
                'available_addons' => $s ? $s->version->availableAddons->map(fn ($a) => [
                    'version' => $a->public_id, 'code' => $a->addon->code, 'name' => $a->name, 'mode' => $a->mode, 'automatic' => (bool) $a->pivot->automatic,
                    'prices' => $a->prices->map(function ($p) {
                        try {
                            $this->catalog->mapping($p);
                            $available = true;
                        } catch (\DomainException) {
                            $available = false;
                        }

                        return ['offer_id' => $p->public_id, 'amount' => $p->amount, 'interval' => $p->interval, 'currency' => $p->currency, 'available' => $available];
                    })->all(),
                ])->all() : [],
                'pending_addon_operations' => $s ? BillingOutbox::where('kind', 'add_addon')->where('payload->contract_id', $s->id)->where(fn ($q) => $q->whereIn('status', ['pending', 'processing', 'reconciliation_required'])->orWhere('result->status', 'awaiting_payment'))->get()->map(fn ($op) => [
                    'status' => $op->result['status'] ?? $op->status, 'quantity' => $op->payload['quantity'],
                    'name' => AddonPrice::find($op->payload['addon_price_id'])?->version?->name,
                    'invoice_url' => $op->result['invoice_url'] ?? null,
                ])->all() : [],
                'pending_contracts' => $tenant->planSubscriptions()
                    ->where('pending_tenant_id', $tenant->id)
                    ->get()
                    ->map(fn ($p) => $this->reconciler->pendingSummary($p))
                    ->all(),
                'entitlements' => $benefits, 'usage_period' => $period,
                'stamp_usage' => $this->stampUsage->for($tenant),
                'usage' => $s ? UsagePeriod::where('plan_subscription_id', $s->id)->where('starts_at', $period['starts_at'])->with('feature')->get()->map(fn ($u) => ['feature' => $u->feature->code, 'consumed' => $u->consumed, 'included' => $u->included, 'starts_at' => $u->starts_at, 'ends_at' => $u->ends_at])->all() : [],
                'addons' => $s ? $s->addons()->whereIn('status', ['active', 'pending'])->with(['price', 'version.addon'])->get()->map(fn ($a) => ['id' => $a->public_id, 'offer_id' => $a->price->public_id, 'mode' => $a->version->mode, 'currency' => $a->price->currency, 'code' => $a->version->addon->code, 'name' => $a->version->name, 'quantity' => $a->quantity, 'status' => $a->status, 'amount' => $a->price->amount, 'interval' => $a->price->interval, 'ends_at' => $a->ends_at])->all() : [],
                'billing_components' => $s ? $s->billingLinks()->get()->map(fn ($l) => ['role' => $l->role, 'status' => $l->status, 'next_billing_at' => $l->period_ends_at])->all() : [],
                'pending_changes' => SubscriptionChange::whereIn('status', ['pending', 'processing'])->with(['targetPrice.version', 'addon.version'])->get()->map(fn ($c) => $c->publicSummary())->all(),
            ],
        );
    }
}
