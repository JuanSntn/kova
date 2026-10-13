<?php

namespace App\Services\Taxes;

use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ProductTaxService
{
    public function __construct(
        private readonly TaxSettingsService $settings,
        private readonly TaxTreatmentCatalog $catalog,
    ) {}

    public function defaultTax(Tenant $tenant): Tax
    {
        $setting = $this->settings->setting($tenant);

        if (! $setting?->defaultTax) {
            throw ValidationException::withMessages([
                'tax' => 'El negocio no tiene un impuesto predeterminado configurado.',
            ]);
        }

        return $setting->defaultTax;
    }

    public function resolveEffectiveTax(Product $product, Tax $defaultTax): Tax
    {
        $tax = $product->tax_id !== null
            ? $product->tax
            : $defaultTax;

        if (! $tax) {
            throw ValidationException::withMessages([
                'tax' => 'El producto no tiene un tratamiento fiscal disponible.',
            ]);
        }

        $this->catalog->assertUsable($tax);

        return $tax;
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return Collection<int, Tax>
     */
    public function effectiveTaxesForProducts(
        Tenant $tenant,
        EloquentCollection $products,
    ): Collection {
        $defaultTax = $this->defaultTax($tenant);

        return $products->mapWithKeys(
            fn (Product $product): array => [
                $product->id => $this->resolveEffectiveTax(
                    product: $product,
                    defaultTax: $defaultTax,
                ),
            ],
        );
    }

    public function calculateIncludedTax(
        string|float|int $grossAmount,
        Tax $tax,
    ): array {
        $lineTotal = \Brick\Math\BigDecimal::of((string) $grossAmount)->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $rate = \Brick\Math\BigDecimal::of((string) $tax->rate);
        $base = $tax->factor_type === 'exempt' || $rate->isZero()
            ? $lineTotal
            : $lineTotal->dividedBy($rate->multipliedBy('0.01')->plus(1), 2, \Brick\Math\RoundingMode::HalfUp);

        return [
            'tax_base' => (string) $base,
            'tax_amount' => (string) $lineTotal->minus($base),
            'line_total' => (string) $lineTotal,
        ];
    }
}
