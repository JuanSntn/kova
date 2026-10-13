<?php

namespace App\Services\Inventory;

use App\Models\Tenant;
use App\Services\Pos\TenantWriteTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryCatalogService
{
    public function quantity(mixed $value): string
    {
        try {
            return (string) BigDecimal::of((string) $value)->toScale(6, RoundingMode::Unnecessary);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['quantity' => 'Usa una cantidad con hasta seis decimales.']);
        }
    }

    public function convert(string $quantity, string $from, string $to): string
    {
        $units = SupplyService::UNITS;
        if (! isset($units[$from], $units[$to]) || $units[$from]['dimension'] !== $units[$to]['dimension']) {
            throw ValidationException::withMessages(['unit' => 'Las unidades no son compatibles.']);
        }
        try {
            return (string) BigDecimal::of($quantity)->multipliedBy($units[$from]['factor'])->dividedBy($units[$to]['factor'], 6, RoundingMode::Unnecessary);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['quantity' => 'La conversión requiere más de seis decimales.']);
        }
    }

    public function category(Tenant $tenant, array $data, ?string $id): array
    {
        return app(TenantWriteTransaction::class)->run($tenant, function () use ($tenant, $data, $id) {
            app(SupplyService::class)->access($tenant, true);
            $record = $id ? DB::table('inventory_categories')->where('tenant_id', $tenant->id)->where('public_id', $id)->first() : null;
            abort_if($id && ! $record, 404);
            $name = trim($data['name']);
            $normal = mb_strtolower($name);
            $duplicate = DB::table('inventory_categories')->where('tenant_id', $tenant->id)->where('normalized_name', $normal);
            if ($record) {
                $duplicate->where('id', '!=', $record->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
            }
            $public = $id ?? (string) Str::uuid();
            if ($record) {
                DB::table('inventory_categories')->where('id', $record->id)->update(['name' => $name, 'normalized_name' => $normal, 'updated_at' => now()]);
                DB::table('inventory_supplies')->where('category_id', $record->id)->update(['category' => $name]);
            } else {
                DB::table('inventory_categories')->insert(['public_id' => $public, 'tenant_id' => $tenant->id, 'name' => $name, 'normalized_name' => $normal, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['id' => $public, 'name' => $name];
        }, 'INVENTORY_RETRY');
    }

    public function recipe(Tenant $tenant, array $data, ?string $id): array
    {
        return app(TenantWriteTransaction::class)->run($tenant, function () use ($tenant, $data, $id) {
            app(SupplyService::class)->access($tenant, true);
            $old = $id ? DB::table('inventory_recipes')->where('tenant_id', $tenant->id)->where('public_id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            abort_if($old && $old->version !== ($data['version'] ?? null), 409, 'La receta cambió. Recarga antes de guardar.');
            if ($old && $old->type !== $data['type']) {
                throw ValidationException::withMessages(['type' => 'El tipo de receta no puede cambiar.']);
            }
            if ($data['type'] === 'recipe') {
                $data['yield_quantity'] = '1';
                $data['yield_unit'] = 'pz';
            }
            SupplyService::requireWholePieces($data['yield_quantity'], $data['yield_unit'], 'yield_quantity');
            $output = null;
            if ($data['type'] === 'production') {
                $output = DB::table('inventory_supplies')->where('tenant_id', $tenant->id)->where('public_id', $data['output_supply_id'] ?? '')->where('produced', true)->first();
                if (! $output) {
                    throw ValidationException::withMessages(['output_supply_id' => 'Selecciona un insumo producido de este negocio.']);
                }
                $this->convert($data['yield_quantity'], $data['yield_unit'], $output->base_unit);
            }
            foreach ($data['components'] as $i => $component) {
                $table = $component['type'] === 'supply' ? 'inventory_supplies' : 'inventory_recipes';
                $target = DB::table($table)->where('tenant_id', $tenant->id)->where('public_id', $component['id'])->first();
                if (! $target || ($component['type'] === 'subrecipe' && $target->type !== 'subrecipe')) {
                    throw ValidationException::withMessages(["components.$i" => 'El componente no es válido para este negocio.']);
                }
                $unit = $component['type'] === 'supply' ? $target->base_unit : $target->yield_unit;
                SupplyService::requireWholePieces($component['quantity'], $component['unit'], "components.$i.quantity");
                $this->convert($component['quantity'], $component['unit'], $unit);
                if ($output && $component['type'] === 'supply' && $target->id === $output->id) {
                    throw ValidationException::withMessages(['components' => 'Una producción no puede consumir su propio resultado.']);
                }
            }
            $public = $id ?? (string) Str::uuid();
            $values = ['name' => $data['name'], 'type' => $data['type'], 'yield_quantity' => $this->quantity($data['yield_quantity']), 'yield_unit' => $data['yield_unit'],
                'output_supply_id' => $output?->id, 'components' => json_encode($data['components']), 'updated_at' => now()];
            if ($old) {
                DB::table('inventory_recipes')->where('id', $old->id)->update($values + ['version' => $old->version + 1]);
            } else {
                DB::table('inventory_recipes')->insert($values + ['public_id' => $public, 'tenant_id' => $tenant->id, 'created_at' => now()]);
            }
            $recipe = DB::table('inventory_recipes')->where('tenant_id', $tenant->id)->where('public_id', $public)->first();
            // Validate every reference path after a change, including indirect cycles.
            foreach (DB::table('inventory_recipes')->where('tenant_id', $tenant->id)->get() as $dependent) {
                $expanded = $this->expand($tenant, $dependent, '1', []);
                if ($dependent->output_supply_id && isset($expanded[$dependent->output_supply_id])) {
                    throw ValidationException::withMessages(['components' => 'Una producción no puede consumir su resultado mediante una subreceta.']);
                }
            }

            return $this->presentRecipe($recipe);
        }, 'INVENTORY_RETRY');
    }

    public function presentRecipe(object $r): array
    {
        return ['id' => $r->public_id, 'name' => $r->name, 'type' => $r->type, 'version' => $r->version,
            'yield_quantity' => $r->yield_quantity, 'yield_unit' => $r->yield_unit,
            'output_supply_id' => $r->output_supply_id ? DB::table('inventory_supplies')->where('id', $r->output_supply_id)->value('public_id') : null,
            'components' => json_decode($r->components, true)];
    }

    /** Expands only virtual subrecipes; produced supplies remain terminal inventory items. */
    public function expand(Tenant $tenant, object $recipe, string $batches, array $path): array
    {
        if (in_array($recipe->id, $path, true) || count($path) >= 20) {
            throw ValidationException::withMessages(['components' => 'Las recetas no pueden tener ciclos ni superar 20 niveles.']);
        }
        $path[] = $recipe->id;
        $result = [];
        foreach (json_decode($recipe->components, true) as $component) {
            $amount = BigDecimal::of($component['quantity'])->multipliedBy($batches);
            if ($component['type'] === 'supply') {
                $s = DB::table('inventory_supplies')->where('tenant_id', $tenant->id)->where('public_id', $component['id'])->first();
                abort_unless($s, 422, 'Un insumo de la receta ya no está disponible.');
                $quantities = [$s->id => $this->convert((string) $amount, $component['unit'], $s->base_unit)];
            } else {
                $sub = DB::table('inventory_recipes')->where('tenant_id', $tenant->id)->where('type', 'subrecipe')->where('public_id', $component['id'])->first();
                abort_unless($sub, 422, 'Una subreceta ya no está disponible.');
                $converted = $this->convert((string) $amount, $component['unit'], $sub->yield_unit);
                try {
                    $factor = BigDecimal::of($converted)->dividedBy($sub->yield_quantity, 12, RoundingMode::Unnecessary);
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages(['components' => 'Ajusta el rendimiento para expresar el consumo con precisión.']);
                }
                $quantities = $this->expand($tenant, $sub, (string) $factor, $path);
            }
            foreach ($quantities as $supply => $quantity) {
                $result[$supply] = (string) BigDecimal::of($result[$supply] ?? '0')->plus($quantity);
            }
        }

        return $result;
    }
}
