<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalSeriesService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FiscalSeriesController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(
        Request $request,
        CurrentTenant $currentTenant,
        FiscalSeriesService $series
    ): JsonResponse {
        abort_unless(
            $request
                ->user()
                ?->can(
                    PermissionName::FiscalView->value
                ),
            403
        );

        $this->assertFiscalAvailable(
            $currentTenant
        );

        return response()->json([
            'data' =>
            $series->configuration(
                $currentTenant->get()
            ),
        ]);
    }

    public function store(
        Request $request,
        CurrentTenant $currentTenant,
        FiscalSeriesService $series
    ): JsonResponse {
        abort_unless(
            $request
                ->user()
                ?->can(
                    PermissionName::FiscalManage->value
                ),
            403
        );

        $this->assertFiscalAvailable(
            $currentTenant
        );

        $data =
            $request->validate([
                'series' => [
                    'required',
                    'string',
                    'max:25',
                    'regex:/^[A-Za-z0-9]+$/',
                ],
            ]);

        $series->create(
            $currentTenant->get(),
            $data['series']
        );

        return response()->json(
            [
                'data' =>
                $series->configuration(
                    $currentTenant->get()
                ),
            ],
            201
        );
    }

    public function setDefault(
        Request $request,
        CurrentTenant $currentTenant,
        FiscalSeriesService $series
    ): JsonResponse {
        abort_unless(
            $request
                ->user()
                ?->can(
                    PermissionName::FiscalManage->value
                ),
            403
        );

        $this->assertFiscalAvailable(
            $currentTenant
        );

        $data =
            $request->validate([
                'series' => [
                    'required',
                    'string',
                    'max:25',
                    'regex:/^[A-Za-z0-9]+$/',
                ],
            ]);

        $series->setDefault(
            $currentTenant->get(),
            $data['series']
        );

        return response()->json([
            'data' =>
            $series->configuration(
                $currentTenant->get()
            ),
        ]);
    }

    private function assertFiscalAvailable(
        CurrentTenant $currentTenant
    ): void {
        abort_unless(
            $this->entitlements->allows(
                $currentTenant->get(),
                'allows_invoicing'
            ),
            403,
            'Tu plan actual no incluye facturación fiscal.'
        );
    }
}
