<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SaveSupplyRequest;
use App\Models\Tax;
use App\Services\Inventory\SupplyService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplyController extends Controller
{
    public function index(Request $request, CurrentTenant $context, SupplyService $service)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $tenant = $context->get();
        $service->access($tenant);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('inventory_supplies')->where('tenant_id', $tenant->id);
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(25);
        $presentations = DB::table('inventory_presentations')->whereIn('supply_id', $page->pluck('id'))->get()->groupBy('supply_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $service->resource($s, $presentations->get($s->id, collect()))),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'can_manage' => $request->user()->can('inventory.manage')],
            'units' => SupplyService::UNITS,
            'taxes' => Tax::where('tenant_id', $tenant->id)->where('is_active', true)->get()->map(fn ($tax) => ['id' => $tax->public_id, 'name' => $tax->name]),
        ]);
    }

    public function store(SaveSupplyRequest $request, CurrentTenant $context, SupplyService $service)
    {
        return response()->json(['data' => $service->save($context->get(), $request->validated(), null)], 201);
    }

    public function update(SaveSupplyRequest $request, string $supply, CurrentTenant $context, SupplyService $service)
    {
        return response()->json(['data' => $service->save($context->get(), $request->validated(), $supply)]);
    }
}
