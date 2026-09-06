<?php

namespace App\Services\Taxes;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ProductTaxService
{
    public function defaultTax(Tenant $tenant): Tax
    {
        $setting = $tenant
            ->taxSettings()
            ->with('defaultTax')
            ->first();

        if (! $setting?->defaultTax) {
            app(TenantTaxProvisioner::class)->provisionDefaults($tenant);

            $setting = $tenant
                ->taxSettings()
                ->with('defaultTax')
                ->first();
        }

        if (! $setting?->defaultTax) {
            throw ValidationException::withMessages([
                'tax' => 'El negocio no tiene un impuesto predeterminado configurado.',
            ]);
        }

        return $setting->defaultTax;
    }

    public function resolveEffectiveTax(Product $product, Tax $defaultTax): Tax
    {
        return $product->tax_id !== null
            ? $product->tax
            : $defaultTax;
    }

    /**
     * @param EloquentCollection<int, Product> $products
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
        $lineTotal = $this->roundMoney((float) $grossAmount);

        if ($tax->factor_type === 'exempt' || (float) $tax->rate === 0.0) {
            return [
                'tax_base' => $lineTotal,
                'tax_amount' => 0.0,
                'line_total' => $lineTotal,
            ];
        }

        $divisor = 1 + (((float) $tax->rate) / 100);
        $taxBase = $this->roundMoney($lineTotal / $divisor);
        $taxAmount = $this->roundMoney($lineTotal - $taxBase);

        return [
            'tax_base' => $taxBase,
            'tax_amount' => $taxAmount,
            'line_total' => $this->roundMoney($taxBase + $taxAmount),
        ];
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}
