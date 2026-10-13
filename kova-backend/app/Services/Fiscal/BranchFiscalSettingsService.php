<?php

namespace App\Services\Fiscal;

use App\Models\Branch;
use App\Models\BranchFiscalSetting;
use App\Models\FiscalSeries;
use App\Models\Tenant;

final class BranchFiscalSettingsService
{
    public function effectiveSeries(
        Tenant $tenant,
        Branch $branch,
        string $defaultSeries
    ): FiscalSeries {
        abort_unless(
            (int) $branch->tenant_id ===
                (int) $tenant->id,
            404
        );

        $branch->loadMissing(
            'fiscalSetting.series'
        );

        if ($branch->fiscalSetting) {
            $series =
                $branch
                ->fiscalSetting
                ->series;

            abort_unless(
                $series &&
                    (int) $series->tenant_id ===
                    (int) $tenant->id &&
                    $series->is_active,
                409,
                'La serie fiscal de la sucursal no está disponible.'
            );

            return $series;
        }

        $defaultSeries =
            strtoupper(
                trim($defaultSeries)
            );

        return FiscalSeries::query()
            ->firstOrCreate(
                [
                    'tenant_id' =>
                    $tenant->id,

                    'series' =>
                    $defaultSeries,
                ],
                [
                    'next_folio' =>
                    1,

                    'is_active' =>
                    true,
                ]
            );
    }

    public function override(
        Tenant $tenant,
        Branch $branch,
        string $seriesCode
    ): BranchFiscalSetting {
        abort_unless(
            (int) $branch->tenant_id ===
                (int) $tenant->id,
            404
        );

        $seriesCode =
            strtoupper(
                trim($seriesCode)
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
            ->firstOrFail();

        return BranchFiscalSetting::query()
            ->updateOrCreate(
                [
                    'branch_id' =>
                    $branch->id,
                ],
                [
                    'fiscal_series_id' =>
                    $series->id,
                ]
            );
    }

    public function inherit(
        Tenant $tenant,
        Branch $branch
    ): void {
        abort_unless(
            (int) $branch->tenant_id ===
                (int) $tenant->id,
            404
        );

        BranchFiscalSetting::query()
            ->where(
                'branch_id',
                $branch->id
            )
            ->delete();
    }
}
