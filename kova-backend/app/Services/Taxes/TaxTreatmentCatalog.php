<?php

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

final class TaxTreatmentCatalog
{
    /**
     * @return list<array{name: string, code: string, factor_type: string, rate: string}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'IVA 16%',
                'code' => 'iva',
                'factor_type' => 'rate',
                'rate' => '16.0000',
            ],
            [
                'name' => 'IVA 0%',
                'code' => 'iva',
                'factor_type' => 'rate',
                'rate' => '0.0000',
            ],
            [
                'name' => 'Exento',
                'code' => 'iva',
                'factor_type' => 'exempt',
                'rate' => '0.0000',
            ],
        ];
    }

    /**
     * @return array{name: string, code: string, factor_type: string, rate: string}
     */
    public function defaultDefinition(): array
    {
        return $this->definitions()[0];
    }

    public function supports(Tax $tax): bool
    {
        foreach ($this->definitions() as $definition) {
            if (
                strtolower($tax->code) === $definition['code']
                && $tax->factor_type === $definition['factor_type']
                && number_format((float) $tax->rate, 4, '.', '') === $definition['rate']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<Tax, Tenant>
     */
    public function query(Tenant $tenant): HasMany
    {
        return $tenant
            ->taxes()
            ->where(function (Builder $query): void {
                foreach ($this->definitions() as $definition) {
                    $query->orWhere(function (Builder $treatment) use ($definition): void {
                        $treatment
                            ->where('code', $definition['code'])
                            ->where('factor_type', $definition['factor_type'])
                            ->where('rate', $definition['rate']);
                    });
                }
            });
    }

    public function findActive(Tenant $tenant, string $publicId): Tax
    {
        return $this->query($tenant)
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function assertUsable(Tax $tax): void
    {
        $this->assertSupported($tax);

        if ($tax->is_active) {
            return;
        }

        throw ValidationException::withMessages([
            'tax_id' => 'El impuesto seleccionado está inactivo.',
        ]);
    }

    public function assertSupported(Tax $tax): void
    {
        if ($this->supports($tax)) {
            return;
        }

        throw ValidationException::withMessages([
            'tax_id' => 'El impuesto seleccionado no es un tratamiento fiscal disponible en Kova.',
        ]);
    }
}
