<?php

namespace App\Services\Inventory;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branches\BranchAccessService;
use App\Services\Pos\TenantWriteTransaction;
use App\Services\Subscriptions\EntitlementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryLedgerService
{
    public function __construct(private InventoryCatalogService $catalog) {}

    public function branch(Tenant $t, User $u, ?string $id, bool $write = false): Branch
    {
        $access = app(BranchAccessService::class);
        if ($id) {
            abort_unless(Str::isUuid($id), 404);
            $b = $t->branches()->where('public_id', $id)->firstOrFail();
            $write ? $access->assertOperate($u, $t, $b, 'inventory.manage') : $access->assertHistory($u, $t, $b, 'inventory.view');

            return $b;
        }
        $branches = $access->operableFor($u, $t, $write ? 'inventory.manage' : 'inventory.view');
        abort_unless($branches->count() === 1, 409, 'Selecciona una sucursal.');

        return $branches->sole();
    }

    public function perform(Tenant $t, User $u, string $key, ?string $branchId, string $type, array $data): array
    {
        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $u, $key, $branchId, $type, $data) {
            $hash = hash('sha256', json_encode([$branchId, $type, $data], JSON_THROW_ON_ERROR));
            $old = DB::table('inventory_operations')->where('tenant_id', $t->id)->where('operation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->user_id === (int) $u->id, 404);
                $b = $t->branches()->whereKey($old->branch_id)->firstOrFail();
                app(BranchAccessService::class)->assertHistory($u, $t, $b, 'inventory.manage');
                if (! hash_equals($old->fingerprint, $hash)) {
                    throw new DomainConflictException('La clave pertenece a otra operación.', 'INVENTORY_IDEMPOTENCY_CONFLICT');
                }

                return json_decode($old->snapshot, true);
            }
            app(SupplyService::class)->access($t, true);
            $b = $this->branch($t, $u, $branchId, true);
            $deltas = [];
            $source = $data;
            if ($type === 'PRODUCTION') {
                $r = DB::table('inventory_recipes')->where('tenant_id', $t->id)->where('public_id', $data['recipe_id'])->where('type', 'production')->first();
                abort_unless($r, 404);
                if ($r->version !== $data['version']) { throw new DomainConflictException('La receta cambió. Actualiza antes de producir.', 'INVENTORY_RECIPE_CHANGED'); }
                $consumption = $this->catalog->expand($t, $r, $data['batches'], []);
                foreach ($consumption as $id => $q) {
                    $deltas[$id] = (string) BigDecimal::of($q)->negated();
                }
                $output = DB::table('inventory_supplies')->where('tenant_id', $t->id)->where('id', $r->output_supply_id)->first();
                abort_unless($output, 422);
                $deltas[$output->id] = $this->catalog->convert((string) BigDecimal::of($r->yield_quantity)->multipliedBy($data['batches']), $r->yield_unit, $output->base_unit);
                $source['recipe'] = $this->catalog->presentRecipe($r);
            } else {
                $s = DB::table('inventory_supplies')->where('tenant_id', $t->id)->where('public_id', $data['supply_id'])->first();
                abort_unless($s, 404);
                $inputUnit = $s->base_unit;
                $q = $this->catalog->quantity($data['quantity']);
                if (! empty($data['presentation_id'])) {
                    $p = DB::table('inventory_presentations')->where('supply_id', $s->id)->where('public_id', $data['presentation_id'])->first();
                    abort_unless($p, 422, 'La presentación no pertenece al insumo.');
                    $inputUnit = $p->unit;
                    $q = $this->catalog->quantity((string) BigDecimal::of($q)->multipliedBy($p->base_quantity));
                    $source['presentation'] = ['name' => $p->name, 'base_quantity' => (string) $p->base_quantity];
                }
                SupplyService::requireWholePieces($data['quantity'], $inputUnit);
                if ($type === 'INITIAL' && DB::table('inventory_balances')->where('branch_id', $b->id)->where('supply_id', $s->id)->exists()) {
                    throw new DomainConflictException('Este insumo ya tiene actividad. Registra un ajuste.', 'INVENTORY_INITIAL_EXISTS');
                }
                if ($type === 'COUNT') {
                    $current = DB::table('inventory_balances')->where('branch_id', $b->id)->where('supply_id', $s->id)->value('quantity') ?? '0';
                    if (! BigDecimal::of($current)->isEqualTo($data['expected_quantity'])) {
                        throw new DomainConflictException('La existencia cambió durante el conteo. Actualiza y revisa la cantidad física.', 'INVENTORY_COUNT_STALE');
                    }
                    $source['previous_quantity'] = (string) $current;
                    $source['counted_quantity'] = $q;
                    $q = (string) BigDecimal::of($q)->minus($current);
                }
                $deltas[$s->id] = in_array($type, ['OUT', 'WASTE'], true) ? (string) BigDecimal::of($q)->negated() : $q;
            }

            return $this->record($t, $b, $u->id, $key, $hash, $type, $data['reason'], $deltas, $source);
        }, 'INVENTORY_RETRY');
    }

    private function record(Tenant $t, Branch $b, int $userId, string $key, string $hash, string $type, string $reason, array $deltas, array $source, ?int $orderId = null): array
    {
        $public = (string) Str::uuid();
        $operation = DB::table('inventory_operations')->insertGetId(['public_id' => $public, 'tenant_id' => $t->id, 'branch_id' => $b->id, 'user_id' => $userId,
            'operation_key' => $key, 'fingerprint' => $hash, 'type' => $type, 'order_id' => $orderId, 'reason' => $reason, 'snapshot' => '{}', 'created_at' => now()]);
        $lines = [];
        ksort($deltas);
        foreach ($deltas as $id => $q) {
            $s = DB::table('inventory_supplies')->where('tenant_id', $t->id)->where('id', $id)->first();
            abort_unless($s, 422);
            SupplyService::requireWholePieces($q, $s->base_unit);
            $balance = DB::table('inventory_balances')->where('branch_id', $b->id)->where('supply_id', $s->id)->first();
            $after = $this->catalog->quantity((string) BigDecimal::of($balance->quantity ?? '0')->plus($q));
            if (BigDecimal::of($after)->abs()->isGreaterThan('999999999999')) {
                throw ValidationException::withMessages(['quantity' => 'El saldo supera el máximo permitido.']);
            }
            $values = ['quantity' => $after, 'updated_at' => now()];
            if ($balance) {
                DB::table('inventory_balances')->where('id', $balance->id)->update($values);
            } else {
                DB::table('inventory_balances')->insert($values + ['tenant_id' => $t->id, 'branch_id' => $b->id, 'supply_id' => $s->id, 'initial_recorded' => $type === 'INITIAL', 'created_at' => now()]);
            }
            DB::table('inventory_movements')->insert(['public_id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'branch_id' => $b->id, 'operation_id' => $operation,
                'supply_id' => $s->id, 'supply_name' => $s->name, 'unit' => $s->base_unit, 'quantity' => $this->catalog->quantity($q), 'balance_after' => $after, 'created_at' => now()]);
            $lines[] = ['supply_id' => $s->public_id, 'name' => $s->name, 'unit' => $s->base_unit, 'quantity' => $this->catalog->quantity($q), 'balance_after' => $after, 'negative' => BigDecimal::of($after)->isNegative()];
        }
        $snapshot = ['id' => $public, 'type' => $type, 'reason' => $reason, 'branch_id' => $b->public_id, 'created_at' => now()->toIso8601String(), 'lines' => $lines, 'source' => $source];
        DB::table('inventory_operations')->where('id', $operation)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

        return $snapshot;
    }

    /** Checkout already owns the TenantWriteTransaction; consumption commits with the sale. */
    public function consumeOrder(Tenant $t, Branch $b, Order $order): void
    {
        if (! app(EntitlementService::class)->allows($t, 'allows_inventory')) {
            return;
        }
        if (DB::table('inventory_operations')->where('order_id', $order->id)->exists()) {
            return;
        }
        $deltas = [];
        $source = ['folio' => $order->folio, 'products' => []];
        foreach ($order->items()->get() as $line) {
            if ($line->inventory_snapshot !== null) {
                $source['products'][] = ['name' => $line->product_name_snapshot, 'sold_quantity' => $line->quantity, 'modifiers' => $line->modifiers_snapshot, 'consumption' => $line->inventory_snapshot];
                foreach ($line->inventory_snapshot as $consumed) {
                    $id = DB::table('inventory_supplies')->where('tenant_id', $t->id)->where('public_id', $consumed['id'])->value('id');
                    abort_unless($id, 422);
                    $q = BigDecimal::of($consumed['quantity'])->multipliedBy($line->quantity);
                    $deltas[$id] = (string) BigDecimal::of($deltas[$id] ?? '0')->minus($q);
                }
                continue;
            }
            $link = DB::table('inventory_product_links')->where('tenant_id', $t->id)->where('product_id', $line->product_id)->first();
            if (! $link) {
                continue;
            }
            $factor = (string) BigDecimal::of((string) $line->quantity)->multipliedBy($link->quantity);
            $consumption = $link->supply_id ? [$link->supply_id => $this->catalog->quantity($factor)] : $this->catalog->expand($t, DB::table('inventory_recipes')->where('tenant_id', $t->id)->where('id', $link->recipe_id)->first(), $factor, []);
            $entry = ['name' => $line->product_name_snapshot, 'sold_quantity' => $line->quantity, 'quantity_per_sale' => $link->quantity];
            if ($link->recipe_id) {
                $entry['recipe'] = $this->catalog->presentRecipe(DB::table('inventory_recipes')->where('id', $link->recipe_id)->first());
            }
            $source['products'][] = $entry;
            foreach ($consumption as $id => $q) {
                $deltas[$id] = (string) BigDecimal::of($deltas[$id] ?? '0')->minus($q);
            }
        }
        if ($deltas) {
            $this->record($t, $b, $order->created_by, (string) Str::uuid(), hash('sha256', 'order:'.$order->id), 'SALE', 'Venta '.$order->folio, $deltas, $source, $order->id);
        }
    }
}
