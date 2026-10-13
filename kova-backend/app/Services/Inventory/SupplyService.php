<?php

namespace App\Services\Inventory;

use App\Models\Tax;
use App\Models\Tenant;
use App\Services\Pos\TenantWriteTransaction;
use App\Services\Subscriptions\EntitlementService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplyService
{
    public const UNITS = [
        'pz' => ['label' => 'Pieza', 'dimension' => 'count', 'factor' => 1],
        'g' => ['label' => 'Gramo', 'dimension' => 'mass', 'factor' => 1],
        'kg' => ['label' => 'Kilogramo', 'dimension' => 'mass', 'factor' => 1000],
        'ml' => ['label' => 'Mililitro', 'dimension' => 'volume', 'factor' => 1],
        'L' => ['label' => 'Litro', 'dimension' => 'volume', 'factor' => 1000],
    ];

    /**
     * A count unit represents whole physical items. Keeping this at the
     * domain boundary prevents a half-piece from entering through a stock
     * operation, a recipe, a modifier, or an import path.
     */
    public static function requireWholePieces(string|int|float $quantity, string $unit, string $field = 'quantity'): void
    {
        if ((self::UNITS[$unit]['dimension'] ?? null) !== 'count') {
            return;
        }

        try {
            BigDecimal::of((string) $quantity)->toScale(0, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Las cantidades en piezas deben ser números enteros.']);
        }
    }

    public function access(Tenant $tenant, bool $write = false): void
    {
        $entitlements = app(EntitlementService::class);
        abort_unless($entitlements->allows($tenant, 'allows_inventory'), 403, 'Tu suscripción no incluye inventario.');
        if ($write) {
            $entitlements->assertWritable($tenant);
        }
    }

    public function resource(object $supply, $presentations = null): array
    {
        $presentations ??= DB::table('inventory_presentations')->where('supply_id', $supply->id)->get();

        return [
            'id' => $supply->public_id, 'name' => $supply->name, 'category' => $supply->category,
            'category_id' => DB::table('inventory_categories')->where('id', $supply->category_id)->value('public_id'),
            'unit' => $supply->base_unit, 'minimum' => $supply->minimum === null ? '' : (string) $supply->minimum,
            'ideal' => $supply->ideal === null ? '' : (string) $supply->ideal,
            'produced' => (bool) $supply->produced, 'version' => $supply->version,
            'presentations' => $presentations->map(fn ($p) => [
                'id' => $p->public_id, 'name' => $p->name, 'quantity' => (string) $p->quantity,
                'unit' => $p->unit, 'baseQuantity' => (string) $p->base_quantity,
                'supplier' => $p->supplier ?? '', 'supplierCode' => $p->supplier_code ?? '',
                'price' => $p->price === null ? '' : (string) $p->price,
                'tax' => $p->tax_id ? Tax::find($p->tax_id)?->public_id ?? '' : '',
            ])->values()->all(),
        ];
    }

    public function save(Tenant $tenant, array $data, ?string $id): array
    {
        return app(TenantWriteTransaction::class)->run($tenant, function () use ($tenant, $data, $id) {
            $this->access($tenant, true);
            foreach (['minimum', 'ideal'] as $field) {
                if (($data[$field] ?? null) !== null) {
                    self::requireWholePieces($data[$field], $data['unit'], $field);
                }
            }
            $supply = $id ? DB::table('inventory_supplies')->where('tenant_id', $tenant->id)->where('public_id', $id)->first() : null;
            if ($id) {
                abort_unless($supply, 404);
                abort_if($supply->version !== $data['version'], 409, 'Este insumo cambió. Recarga antes de guardar.');
            }
            if (($data['minimum'] ?? null) !== null && ($data['ideal'] ?? null) !== null && BigDecimal::of($data['ideal'])->isLessThan($data['minimum'])) {
                throw ValidationException::withMessages(['ideal' => 'El nivel ideal debe ser igual o mayor que el mínimo.']);
            }
            if (! $id) {
                abort_if(DB::table('inventory_supplies')->where('public_id', $data['id'])->exists(), 409, 'Este insumo ya fue registrado. Actualiza el listado.');
            }
            $category = DB::table('inventory_categories')->where('tenant_id', $tenant->id)->where('public_id', $data['category_id'] ?? '')->first();
            if (! $category && isset($data['category_id'])) {
                throw ValidationException::withMessages(['category_id' => 'Selecciona una categoría de este negocio.']);
            }
            if (! $category) {
                $normal = mb_strtolower(trim($data['category']));
                $category = DB::table('inventory_categories')->where('tenant_id', $tenant->id)->where('normalized_name', $normal)->first();
                if (! $category) {
                    $categoryId = DB::table('inventory_categories')->insertGetId(['tenant_id' => $tenant->id, 'public_id' => (string) Str::uuid(), 'name' => trim($data['category']), 'normalized_name' => $normal, 'created_at' => now(), 'updated_at' => now()]);
                    $category = DB::table('inventory_categories')->find($categoryId);
                }
            }
            if ($supply && $supply->base_unit !== $data['unit']) {
                $used = DB::table('inventory_balances')->where('supply_id', $supply->id)->exists()
                    || DB::table('inventory_product_links')->where('supply_id', $supply->id)->exists();
                foreach (DB::table('inventory_recipes')->where('tenant_id', $tenant->id)->get() as $r) {
                    $used = $used || $r->output_supply_id === $supply->id || str_contains($r->components, $supply->public_id);
                }
                if ($used) {
                    throw ValidationException::withMessages(['unit' => 'La unidad base no puede cambiar después de usar el insumo en inventario o recetas.']);
                }
            }
            if ($supply && $supply->produced && ! $data['produced'] && DB::table('inventory_recipes')->where('output_supply_id', $supply->id)->exists()) {
                throw ValidationException::withMessages(['produced' => 'Este insumo tiene una receta de producción.']);
            }
            $rows = [];
            foreach ($data['presentations'] as $index => $p) {
                if (self::UNITS[$p['unit']]['dimension'] !== self::UNITS[$data['unit']]['dimension']) {
                    throw ValidationException::withMessages(["presentations.$index.unit" => 'La unidad debe ser compatible con la unidad base.']);
                }
                try {
                    self::requireWholePieces($p['quantity'], $p['unit'], "presentations.$index.quantity");
                    $base = BigDecimal::of($p['quantity'])->multipliedBy(self::UNITS[$p['unit']]['factor'])
                        ->dividedBy(self::UNITS[$data['unit']]['factor'], 6, RoundingMode::Unnecessary);
                } catch (RoundingNecessaryException $e) {
                    throw ValidationException::withMessages(["presentations.$index.quantity" => 'La conversión admite hasta seis decimales en la unidad base.']);
                }
                $tax = empty($p['tax']) ? null : Tax::where('tenant_id', $tenant->id)->where('public_id', $p['tax'])->where('is_active', true)->first();
                if (! empty($p['tax']) && ! $tax) {
                    throw ValidationException::withMessages(["presentations.$index.tax" => 'Selecciona un impuesto activo de este negocio.']);
                }
                $rows[] = [
                    'public_id' => $p['id'], 'name' => trim($p['name']), 'quantity' => $p['quantity'],
                    'unit' => $p['unit'], 'base_quantity' => (string) $base, 'supplier' => $p['supplier'] ?? null,
                    'supplier_code' => $p['supplierCode'] ?? null, 'price' => $p['price'] ?? null,
                    'tax_id' => $tax?->id, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            // Public IDs are identifiers only. Existing foreign presentations may never be reassigned.
            $collision = DB::table('inventory_presentations')->whereIn('public_id', array_column($rows, 'public_id'));
            if ($supply) {
                $collision->where('supply_id', '!=', $supply->id);
            }
            if ($collision->exists()) {
                throw ValidationException::withMessages(['presentations' => 'Una presentación no pertenece a este insumo.']);
            }
            $values = [
                'name' => trim($data['name']), 'category' => $category->name, 'category_id' => $category->id, 'base_unit' => $data['unit'],
                'minimum' => $data['minimum'] ?? null, 'ideal' => $data['ideal'] ?? null, 'produced' => $data['produced'],
                'updated_at' => now(),
            ];
            if ($supply) {
                $updated = DB::table('inventory_supplies')->where('id', $supply->id)->where('version', $data['version'])->update($values + ['version' => $data['version'] + 1]);
                abort_unless($updated === 1, 409, 'Este insumo cambió. Recarga antes de guardar.');
                $internalId = $supply->id;
                DB::table('inventory_presentations')->where('supply_id', $internalId)->whereNotIn('public_id', array_column($rows, 'public_id'))->delete();
            } else {
                abort_if(DB::table('inventory_supplies')->where('public_id', $data['id'])->exists(), 409, 'Este insumo ya fue registrado. Actualiza el listado.');
                $internalId = DB::table('inventory_supplies')->insertGetId($values + ['tenant_id' => $tenant->id, 'public_id' => $data['id'], 'created_at' => now()]);
            }
            foreach ($rows as $row) {
                $existing = DB::table('inventory_presentations')->where('supply_id', $internalId)->where('public_id', $row['public_id'])->first();
                if ($existing) {
                    unset($row['created_at']);
                    DB::table('inventory_presentations')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('inventory_presentations')->insert($row + ['supply_id' => $internalId]);
                }
            }

            return $this->resource(DB::table('inventory_supplies')->find($internalId));
        });
    }
}
