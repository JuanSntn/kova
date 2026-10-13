<?php

namespace App\Services\Fiscal;

use App\Models\FiscalSeries;
use App\Models\Tenant;
use App\Models\TenantFiscalProfile;
use Illuminate\Support\Facades\DB;

final class FiscalSeriesService
{
    public function __construct(
        private readonly FiscalNumberFormatter $formatter,
    ) {}

    public function configuration(
        Tenant $tenant
    ): array {
        $profile =
            TenantFiscalProfile::query()
            ->where(
                'tenant_id',
                $tenant->id
            )
            ->firstOrCreate(
                [
                    'tenant_id' =>
                    $tenant->id,
                ],
                [
                    'status' =>
                    'incomplete',

                    'default_series' =>
                    'A',
                ]
            );

        $this->ensureSeries(
            $tenant,
            $profile->default_series
        );

        $series =
            FiscalSeries::query()
            ->where(
                'tenant_id',
                $tenant->id
            )
            ->where(
                'is_active',
                true
            )
            ->orderBy('series')
            ->get()
            ->map(
                fn(
                    FiscalSeries $item
                ): array => [
                    'series' =>
                    $item->series,

                    'next_folio' =>
                    $item->next_folio,

                    'next_number' =>
                    $this
                        ->formatter
                        ->format(
                            $item->series,
                            $item->next_folio
                        ),

                    'is_default' =>
                    $item->series ===
                        $profile->default_series,

                    'is_active' =>
                    $item->is_active,
                ]
            )
            ->values()
            ->all();

        return [
            'default_series' =>
            $profile->default_series,

            'series' =>
            $series,
        ];
    }

    public function create(
        Tenant $tenant,
        string $seriesCode
    ): FiscalSeries {
        $seriesCode =
            $this->normalize(
                $seriesCode
            );

        abort_if(
            FiscalSeries::query()
                ->where(
                    'tenant_id',
                    $tenant->id
                )
                ->where(
                    'series',
                    $seriesCode
                )
                ->exists(),
            409,
            'La serie fiscal ya existe.'
        );

        return FiscalSeries::query()
            ->create([
                'tenant_id' =>
                $tenant->id,

                'series' =>
                $seriesCode,

                'next_folio' =>
                1,

                'is_active' =>
                true,
            ]);
    }

    public function setDefault(
        Tenant $tenant,
        string $seriesCode
    ): TenantFiscalProfile {
        $seriesCode =
            $this->normalize(
                $seriesCode
            );

        return DB::transaction(
            function () use (
                $tenant,
                $seriesCode
            ): TenantFiscalProfile {
                $profile =
                    TenantFiscalProfile::query()
                    ->where(
                        'tenant_id',
                        $tenant->id
                    )
                    ->lockForUpdate()
                    ->firstOrCreate(
                        [
                            'tenant_id' =>
                            $tenant->id,
                        ],
                        [
                            'status' =>
                            'incomplete',

                            'default_series' =>
                            'A',
                        ]
                    );

                $series =
                    FiscalSeries::query()
                    ->where(
                        'tenant_id',
                        $tenant->id
                    )
                    ->where(
                        'series',
                        $seriesCode
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $series,
                    404,
                    'La serie fiscal no existe o no está activa.'
                );

                /*
                 * Cambiar la serie predeterminada
                 * NO invalida RFC, CSD ni PAC.
                 *
                 * Por eso no utilizamos
                 * FiscalProfileService::update().
                 */
                $profile->update([
                    'default_series' =>
                    $series->series,
                ]);

                return $profile->refresh();
            },
            3
        );
    }

    public function ensureSeries(
        Tenant $tenant,
        string $seriesCode
    ): FiscalSeries {
        $seriesCode =
            $this->normalize(
                $seriesCode
            );

        return FiscalSeries::query()
            ->firstOrCreate(
                [
                    'tenant_id' =>
                    $tenant->id,

                    'series' =>
                    $seriesCode,
                ],
                [
                    'next_folio' =>
                    1,

                    'is_active' =>
                    true,
                ]
            );
    }

    private function normalize(
        string $seriesCode
    ): string {
        return strtoupper(
            trim($seriesCode)
        );
    }
}
