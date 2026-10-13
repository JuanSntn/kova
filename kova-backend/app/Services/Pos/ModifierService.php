<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\InventoryCatalogService;
use App\Services\Inventory\SupplyService;
use App\Services\Subscriptions\EntitlementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ModifierService
{
    public function groups(Tenant $tenant, Product $product): array
    {
        return DB::table('menu_product_modifiers as a')
            ->join('menu_modifier_groups as g', 'g.id', '=', 'a.group_id')
            ->where('a.tenant_id', $tenant->id)->where('g.tenant_id', $tenant->id)
            ->where('a.product_id', $product->id)->where('g.is_active', true)
            ->orderBy('g.id')->get(['g.public_id', 'g.name', 'g.kind', 'g.version', 'g.options as choices', 'a.options', 'a.min_selections', 'a.max_selections'])
            ->map(function ($g) {
                $names = collect(json_decode($g->choices, true))->keyBy('id');
                $options = [];
                foreach (json_decode($g->options, true) as $o) {
                    if (isset($names[$o['id']])) {
                        $options[] = $o + ['name' => $names[$o['id']]['name']];
                    }
                }

                return ['id' => $g->public_id, 'name' => $g->name, 'kind' => $g->kind, 'version' => $g->version,
                    'min' => $g->min_selections, 'max' => $g->max_selections, 'options' => $options];
            })->all();
    }

    /** Resolve every price and inventory effect from tenant-owned configuration. */
    public function resolve(Tenant $tenant, Product $product, array $selected): array
    {
        if (count($selected) !== count(array_unique($selected))) {
            $this->invalid('No repitas una opción.');
        }
        $groups = $this->groups($tenant, $product);
        $found = [];
        $snapshots = [];
        $price = BigDecimal::of('0');
        $replacement = null;
        $effects = [];
        foreach ($groups as $g) {
            $choices = array_values(array_filter($g['options'], fn ($o) => in_array($o['id'], $selected, true)));
            if (count($choices) < $g['min'] || count($choices) > $g['max']) {
                $this->invalid('Revisa las opciones de '.$g['name'].'.');
            }
            foreach ($choices as $o) {
                $found[] = $o['id'];
                $price = $price->plus($o['price']);
                $snapshots[] = ['id' => $o['id'], 'group_name' => $g['name'], 'option_name' => $o['name'], 'price_adjustment' => $o['price'], 'version' => $g['version']];
                if (($o['effect'] ?? 'none') === 'replace') {
                    if ($replacement !== null) {
                        $this->invalid('Sólo se permite una receta de tamaño por producto.');
                    }
                    $replacement = $o;
                } elseif (($o['effect'] ?? 'none') !== 'none') {
                    $effects[] = $o;
                }
            }
        }
        if (count($found) !== count($selected)) {
            $this->invalid('Una opción no pertenece a este producto o ya no está disponible.');
        }
        $inventory = null;
        if (app(EntitlementService::class)->allows($tenant, 'allows_inventory')) {
            $quantities = [];
            if ($replacement) {
                $quantities = $this->effect($tenant, $replacement);
            } else {
                $link = DB::table('inventory_product_links')->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
                if ($link) {
                    $target = DB::table($link->supply_id ? 'inventory_supplies' : 'inventory_recipes')->where('tenant_id', $tenant->id)->where('id', $link->supply_id ?? $link->recipe_id)->first();
                    abort_unless($target, 422);
                    $quantities = $this->effect($tenant, ['target_type' => $link->supply_id ? 'supply' : 'recipe', 'target_id' => $target->public_id, 'quantity' => $link->quantity]);
                }
            }
            foreach ($effects as $o) {
                if ($o['effect'] === 'swap') {
                    foreach ($this->effect($tenant, $this->swapSource($o)) as $id => $q) {
                        $quantities[$id] = (string) BigDecimal::of($quantities[$id] ?? '0')->minus($q);
                    }
                }
                foreach ($this->effect($tenant, $o) as $id => $q) {
                    $quantities[$id] = (string) BigDecimal::of($quantities[$id] ?? '0')->plus($o['effect'] === 'remove' ? BigDecimal::of($q)->negated() : $q);
                }
            }
            $inventory = [];
            foreach ($quantities as $id => $q) {
                if (BigDecimal::of($q)->isNegative()) {
                    $this->invalid('La opción intenta quitar más ingredientes de los que contiene la receta.');
                }
                $s = DB::table('inventory_supplies')->where('tenant_id', $tenant->id)->where('id', $id)->first();
                abort_unless($s, 422);
                $inventory[] = ['id' => $s->public_id, 'name' => $s->name, 'unit' => $s->base_unit, 'quantity' => app(InventoryCatalogService::class)->quantity($q)];
            }
        }

        return ['price' => (string) $price->toScale(2), 'modifiers' => $snapshots, 'inventory' => $inventory];
    }

    public function effect(Tenant $tenant, array $option): array
    {
        if (! in_array($option['target_type'] ?? null, ['supply', 'recipe'], true)) {
            $this->invalid('Selecciona un insumo o receta de venta de este negocio.');
        }
        $supply = $option['target_type'] === 'supply';
        $target = DB::table($supply ? 'inventory_supplies' : 'inventory_recipes')->where('tenant_id', $tenant->id)->where('public_id', $option['target_id'])->first();
        if (! $target || (! $supply && $target->type !== 'recipe')) {
            $this->invalid('Selecciona un insumo o receta de venta de este negocio.');
        }

        if ($supply) {
            SupplyService::requireWholePieces($option['quantity'], $target->base_unit);
        }

        return $supply ? [$target->id => app(InventoryCatalogService::class)->quantity($option['quantity'])]
            : app(InventoryCatalogService::class)->expand($tenant, $target, (string) $option['quantity'], []);
    }

    public function validateEffect(Tenant $tenant, array $option, string $kind): void
    {
        if ($option['effect'] === 'none') {
            return;
        }
        abort_unless(auth()->user()->can('inventory.manage'), 403);
        app(SupplyService::class)->access($tenant, true);
        if ($option['effect'] === 'replace' && ($kind !== 'size' || $option['target_type'] !== 'recipe')) {
            $this->invalid('Sólo un tamaño puede sustituir la receta.');
        }
        if ($kind === 'size' && $option['effect'] !== 'replace') {
            $this->invalid('Un tamaño conserva o sustituye la receta completa.');
        }
        if ($option['effect'] === 'swap') {
            if ($kind !== 'extras') {
                $this->invalid('Sólo un grupo de extras puede sustituir ingredientes.');
            }
            $this->effect($tenant, $this->swapSource($option));
        }
        $this->effect($tenant, $option);
    }

    private function swapSource(array $option): array
    {
        return [
            'target_type' => $option['source_type'] ?? null,
            'target_id' => $option['source_id'] ?? null,
            'quantity' => $option['source_quantity'] ?? null,
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['modifiers' => $message]);
    }
}
