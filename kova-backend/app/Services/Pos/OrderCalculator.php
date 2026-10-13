<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Branches\BranchProductService;
use App\Services\Subscriptions\CatalogAccessService;
use App\Services\Taxes\ProductTaxService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class OrderCalculator
{
    public function __construct(
        private ProductTaxService $taxes,
        private CatalogAccessService $catalog,
        private BranchProductService $branches,
    ) {}

    public function calculate(Tenant $tenant, Branch $branch, array $items): array
    {
        $ids = array_column($items, 'product_id');
        $products = $tenant->products()
            ->with(['tax', 'category', 'branchSettings' => fn ($q) => $q->where('branch_id', $branch->id)])
            ->whereIn('public_id', $ids)->get()->keyBy('public_id');
        $defaultTax = $this->taxes->defaultTax($tenant);
        $lines = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product || ! $this->branches->available($product)) {
                throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles en esta sucursal.']);
            }
            $this->catalog->assertProductUsable($product);
            $this->branches->applyEffective($product);
            $price = BigDecimal::of((string) $product->getAttribute('effective_price'))->toScale(2, RoundingMode::HalfUp);
            $configuration = app(ModifierService::class)->resolve($tenant, $product, $item['modifiers'] ?? []);
            $price = $price->plus($configuration['price']);
            $tax = $this->taxes->resolveEffectiveTax($product, $defaultTax);
            $lines[] = [
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'modifiers_snapshot' => $configuration['modifiers'],
                'inventory_snapshot' => $configuration['inventory'],
                'preparation_station_snapshot' => $product->preparation_station === 'none' ? null : $product->preparation_station,
                'preparation_status' => $product->preparation_station === 'none' ? 'ready' : 'pending',
                'unit_price_snapshot' => (string) $price,
                'quantity' => (int) $item['quantity'],
                'tax_name_snapshot' => $tax->name,
                'tax_code_snapshot' => $tax->code,
                'tax_factor_type_snapshot' => $tax->factor_type,
                'tax_rate_snapshot' => $tax->rate,
                ...$this->taxes->calculateIncludedTax((string) $price->multipliedBy($item['quantity']), $tax),
            ];
        }
        $sum = fn (string $column) => (string) BigDecimal::sum('0.00', ...array_column($lines, $column))->toScale(2);
        if (BigDecimal::of($sum('line_total'))->isGreaterThan('9999999999.99')) {
            throw ValidationException::withMessages(['items' => 'El total supera el importe máximo permitido por venta.']);
        }
        return [
            'lines' => $lines,
            'subtotal' => $sum('line_total'),
            'tax_base_total' => $sum('tax_base'),
            'tax_total' => $sum('tax_amount'),
            'total' => $sum('line_total'),
        ];
    }

    public function fingerprint(array $calculation): string
    {
        return hash('sha256', json_encode($calculation, JSON_THROW_ON_ERROR));
    }
}
