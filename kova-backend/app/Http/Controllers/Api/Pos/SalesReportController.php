<?php

namespace App\Http\Controllers\Api\Pos;

use App\Exceptions\DomainConflictException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Sales\SalesReportService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SalesReportController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SalesReportService $reports,
    ) {}

    public function summary(): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            Order::class,
        );

        try {
            $data = $this
                ->reports
                ->summary(
                    $this
                        ->currentTenant
                        ->get()
                );
        } catch (
            DomainConflictException $exception
        ) {
            return response()->json([
                'message' =>
                $exception->getMessage(),

                'code' =>
                $exception->domainCode,
            ], 409);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
