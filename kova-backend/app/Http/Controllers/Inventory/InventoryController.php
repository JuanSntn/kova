<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Inventory\InventoryCatalogService;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\SupplyService;
use App\Services\Pos\TenantWriteTransaction;
use App\Tenancy\CurrentTenant;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private function tenant(Request $r, bool $write = false)
    {
        abort_unless($r->user()->can($write ? 'inventory.manage' : 'inventory.view'), 403);
        $t = app(CurrentTenant::class)->get();
        app(SupplyService::class)->access($t, $write);

        return $t;
    }

    public function catalog(Request $r)
    {
        $t = $this->tenant($r);

        return response()->json([
            'categories' => DB::table('inventory_categories')->where('tenant_id', $t->id)->orderBy('name')->get(['public_id as id', 'name']),
            'supplies' => DB::table('inventory_supplies')->where('tenant_id', $t->id)->orderBy('name')->get()->map(fn ($s) => app(SupplyService::class)->resource($s)),
            'recipes' => DB::table('inventory_recipes')->where('tenant_id', $t->id)->orderBy('name')->get()->map(fn ($s) => app(InventoryCatalogService::class)->presentRecipe($s)),
            'can_manage' => $r->user()->can('inventory.manage'),
        ]);
    }

    public function category(Request $r, ?string $category = null)
    {
        $t = $this->tenant($r, true);
        $data = $r->validate([
            'name' => ['required', 'string', 'max:100'],
            // The catalog belongs to the current tenant. Its scope is never
            // supplied by the client, even when a branch is selected in the UI.
            'id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        return response()->json(['data' => app(InventoryCatalogService::class)->category($t, $data, $category)]);
    }

    public function recipe(Request $r, ?string $recipe = null)
    {
        $t = $this->tenant($r, true);
        $data = $r->validate([
            'name' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::in(['recipe', 'production', 'subrecipe'])],
            'version' => [$recipe ? 'required' : 'nullable', 'integer', 'min:1'],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'yield_unit' => ['required', Rule::in(array_keys(SupplyService::UNITS))],
            'output_supply_id' => ['nullable', 'uuid', 'required_if:type,production'],
            'components' => ['required', 'array', 'min:1', 'max:100'],
            'components.*' => ['array:type,id,quantity,unit'],
            'components.*.type' => ['required', Rule::in(['supply', 'subrecipe'])],
            'components.*.id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'components.*.unit' => ['required', Rule::in(array_keys(SupplyService::UNITS))],
            'id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        return response()->json(['data' => app(InventoryCatalogService::class)->recipe($t, $data, $recipe)]);
    }

    public function balances(Request $r)
    {
        $t = $this->tenant($r);
        $b = app(InventoryLedgerService::class)->branch($t, $r->user(), $r->header('X-Branch-ID'));
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'uuid']]);
        $query = DB::table('inventory_supplies as s')->leftJoin('inventory_balances as b', function ($j) use ($b) {
            $j->on('s.id', '=', 'b.supply_id')->where('b.branch_id', $b->id);
        })
            ->where('s.tenant_id', $t->id);
        if ($r->filled('category')) {
            $query->whereIn('s.category_id', DB::table('inventory_categories')->where('tenant_id', $t->id)->where('public_id', $r->input('category'))->select('id'));
        }
        if ($r->filled('search')) {
            $query->where('s.name', 'like', '%'.$r->string('search').'%');
        }
        $page = $query->select(['s.public_id as id', 's.name', 's.category', 's.base_unit as unit', 'b.quantity', 's.minimum', 's.ideal'])->orderBy('s.name')->paginate(25);
        $page->getCollection()->transform(function ($s) use ($t, $b) {
            $last = DB::table('inventory_movements as m')->join('inventory_operations as o', 'o.id', '=', 'm.operation_id')->join('inventory_supplies as supply', 'supply.id', '=', 'm.supply_id')
                ->where('m.tenant_id', $t->id)->where('m.branch_id', $b->id)->where('supply.public_id', $s->id)->where('o.type', 'COUNT')->orderByDesc('m.id')->first(['m.balance_after', 'm.created_at']);
            $s->last_count = $last?->balance_after;
            $s->last_count_at = $last?->created_at;
            $s->quantity ??= '0.000000';
            $s->negative = BigDecimal::of($s->quantity)->isNegative();
            $s->below_minimum = $s->minimum !== null && BigDecimal::of($s->quantity)->isLessThan((string) $s->minimum);

            return $s;
        });

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()], 'branch' => ['id' => $b->public_id, 'name' => $b->name]]);
    }

    public function movements(Request $r)
    {
        $t = $this->tenant($r);
        $b = app(InventoryLedgerService::class)->branch($t, $r->user(), $r->header('X-Branch-ID'));
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', Rule::in(['INITIAL', 'IN', 'OUT', 'WASTE', 'PRODUCTION', 'SALE', 'COUNT'])]]);
        $q = DB::table('inventory_operations')->where('tenant_id', $t->id)->where('branch_id', $b->id);
        if ($r->filled('type')) {
            $q->where('type', $r->input('type'));
        }
        $page = $q->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => json_decode($o->snapshot, true) + ['author' => DB::table('users')->where('id', $o->user_id)->value('name')]), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function operation(Request $r)
    {
        // Replay authorization and entitlement checks occur inside the serialized service.
        abort_unless($r->user()->can('inventory.manage'), 403);
        $t = app(CurrentTenant::class)->get();
        $key = validator(['key' => $r->header('Idempotency-Key')], ['key' => ['required', 'uuid']])->validate()['key'];
        $data = $r->validate([
            'type' => ['required', Rule::in(['INITIAL', 'IN', 'OUT', 'WASTE', 'PRODUCTION', 'COUNT'])],
            'reason' => ['required', 'string', 'max:500'],
            'supply_id' => ['required_unless:type,PRODUCTION', 'nullable', 'uuid'],
            'quantity' => ['required_unless:type,PRODUCTION', 'nullable', 'numeric', $r->input('type') === 'COUNT' ? 'min:0' : 'gt:0', 'max:999999999', 'decimal:0,6'],
            'presentation_id' => ['prohibited_if:type,COUNT', 'nullable', 'uuid'],
            'expected_quantity' => ['required_if:type,COUNT', 'numeric', 'decimal:0,6'],
            'recipe_id' => ['required_if:type,PRODUCTION', 'nullable', 'uuid'],
            'version' => ['required_if:type,PRODUCTION', 'nullable', 'integer', 'min:1'],
            'batches' => ['required_if:type,PRODUCTION', 'nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'tenant_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'user_id' => ['prohibited'], 'balance' => ['prohibited'],
        ]);

        return response()->json(['data' => app(InventoryLedgerService::class)->perform($t, $r->user(), strtolower($key), $r->header('X-Branch-ID'), $data['type'], $data)]);
    }

    public function products(Request $r)
    {
        $t = $this->tenant($r);
        $r->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'], 'consumption' => ['nullable', Rule::in(['none', 'supply', 'recipe'])]]);
        $query = DB::table('products as p')
            ->leftJoin('inventory_product_links as l', function ($join) use ($t) {
                $join->on('l.product_id', '=', 'p.id')->where('l.tenant_id', $t->id);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('inventory_supplies as s', 's.id', '=', 'l.supply_id')
            ->leftJoin('inventory_recipes as r', 'r.id', '=', 'l.recipe_id')
            ->where('p.tenant_id', $t->id)->whereNull('p.deleted_at');
        if ($r->filled('search')) {
            $query->where('p.name', 'like', '%'.$r->input('search').'%');
        }
        if ($r->input('consumption') === 'none') {
            $query->whereNull('l.id');
        } elseif ($r->input('consumption') === 'supply') {
            $query->whereNotNull('l.supply_id');
        } elseif ($r->input('consumption') === 'recipe') {
            $query->whereNotNull('l.recipe_id');
        }
        $page = $query->select(['p.public_id as id', 'p.name', 'p.is_active', 'c.name as category', 'l.quantity', 's.public_id as supply_id', 's.name as supply_name', 's.base_unit as unit', 'r.public_id as recipe_id', 'r.name as recipe_name'])
            ->orderBy('p.name')->orderBy('p.id')->paginate(25);
        $rows = $page->getCollection()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'active' => (bool) $p->is_active, 'category' => $p->category,
            'consumption' => ['type' => $p->supply_id ? 'supply' : ($p->recipe_id ? 'recipe' : 'none'), 'target_id' => $p->supply_id ?? $p->recipe_id ?? '', 'name' => $p->supply_name ?? $p->recipe_name, 'unit' => $p->unit, 'quantity' => $p->quantity ?? '1'],
        ]);

        return response()->json(['data' => $rows, 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'can_manage' => $r->user()->can('inventory.manage')]]);
    }

    public function productLink(Request $r, string $product)
    {
        $t = $this->tenant($r, ! $r->isMethod('GET'));
        $p = Product::where('tenant_id', $t->id)->where('public_id', $product)->firstOrFail();
        if ($r->isMethod('GET')) {
            $link = DB::table('inventory_product_links')->where('tenant_id', $t->id)->where('product_id', $p->id)->first();

            return response()->json(['data' => ! $link ? ['type' => 'none', 'quantity' => '1', 'target_id' => ''] : [
                'type' => $link->supply_id ? 'supply' : 'recipe', 'quantity' => $link->quantity,
                'target_id' => $link->supply_id ? DB::table('inventory_supplies')->where('id', $link->supply_id)->value('public_id') : DB::table('inventory_recipes')->where('id', $link->recipe_id)->value('public_id'),
            ]]);
        }
        $data = $r->validate([
            'type' => ['required', Rule::in(['none', 'supply', 'recipe'])],
            'target_id' => ['nullable', 'uuid', 'required_unless:type,none'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            // Product and inventory target are resolved server-side within the
            // current tenant. Accepting these scope fields invites IDOR bugs.
            'id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'product_id' => ['prohibited'],
        ]);

        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $p, $data) {
            app(SupplyService::class)->access($t, true);
            if ($data['type'] === 'none') {
                DB::table('inventory_product_links')->where('tenant_id', $t->id)->where('product_id', $p->id)->delete();
            } else {
                $q = DB::table($data['type'] === 'supply' ? 'inventory_supplies' : 'inventory_recipes')->where('tenant_id', $t->id)->where('public_id', $data['target_id']);
                if ($data['type'] === 'recipe') {
                    $q->where('type', 'recipe');
                }
                $target = $q->first();
                abort_unless($target, 422, 'Selecciona un insumo o receta de este negocio.');
                if ($data['type'] === 'supply') {
                    SupplyService::requireWholePieces((string) $data['quantity'], $target->base_unit);
                }
                DB::table('inventory_product_links')->updateOrInsert(['tenant_id' => $t->id, 'product_id' => $p->id], [
                    'supply_id' => $data['type'] === 'supply' ? $target->id : null, 'recipe_id' => $data['type'] === 'recipe' ? $target->id : null,
                    'quantity' => app(InventoryCatalogService::class)->quantity($data['quantity']), 'updated_at' => now(), 'created_at' => now(),
                ]);
            }

            return response()->json(['message' => 'Consumo actualizado.']);
        }, 'INVENTORY_RETRY');
    }
}
