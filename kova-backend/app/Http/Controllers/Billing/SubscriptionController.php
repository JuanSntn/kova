<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AddonPrice;
use App\Models\BillingOutbox;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionChange;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SubscriptionController extends Controller
{
    public function offers(Request $r, CatalogService $catalog)
    {
        abort_unless($r->user()->can('billing.view'), 403);

        return response()->json(['data' => $catalog->offers()]);
    }

    public function changes(Request $r)
    {
        abort_unless($r->user()->can('billing.view'), 403);

        return response()->json(['data' => SubscriptionChange::whereIn('status', ['pending', 'processing'])->with(['targetPrice.version', 'addon.version'])->get()->map(fn ($c) => $c->publicSummary())]);
    }

    public function cancelChange(Request $r, string $id, ContractService $contracts, BillingProcessor $processor)
    {
        abort_unless($r->user()->can('billing.manage'), 403);
        $op = $contracts->cancelChange(SubscriptionChange::where('public_id', $id)->firstOrFail());
        $processor->run($op);

        return response()->json(['status' => 'cancelled']);
    }

    public function add(Request $r, CurrentTenant $tenant, ContractService $contracts, EntitlementService $access, BillingProcessor $processor)
    {
        abort_unless($r->user()->can('billing.manage'), 403);
        $data = $r->validate(['offer_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:100'], 'tenant_id' => ['prohibited'], 'amount' => ['prohibited'], 'price_id' => ['prohibited']]);
        $s = $access->current($tenant->get());
        abort_unless($s, 409);
        $op = $contracts->add($s, AddonPrice::where('public_id', $data['offer_id'])->firstOrFail(), $data['quantity'], $r->header('Idempotency-Key') ?: (string) Str::uuid());

        return response()->json($processor->run($op), 202);
    }

    public function reduce(Request $r, string $id, CurrentTenant $tenant, ContractService $contracts, EntitlementService $access, BillingProcessor $processor)
    {
        abort_unless($r->user()->can('billing.manage'), 403);
        $data = $r->validate(['quantity' => ['required', 'integer', 'min:0'], 'retained_branch_ids' => ['sometimes', 'array'], 'retained_branch_ids.*' => ['uuid', 'distinct']]);
        $s = $access->current($tenant->get());
        abort_unless($s, 409);
        $change = $contracts->reduce($s, SubscriptionAddon::where('public_id', $id)->firstOrFail(), $data['quantity'], $data['retained_branch_ids'] ?? []);
        $op = BillingOutbox::where('operation_key', $s->tenant_id.':reduce:'.$change->public_id)->firstOrFail();
        $processor->run($op);

        return response()->json(['id' => $change->public_id, 'status' => $change->status, 'effective_at' => $change->effective_at], 202);
    }
}
