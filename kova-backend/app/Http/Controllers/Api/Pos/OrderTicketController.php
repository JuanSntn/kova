<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Services\Tickets\TicketService;
use App\Tenancy\CurrentTenant;
use App\Services\Branches\BranchAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrderTicketController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TicketService $tickets,
        private readonly BranchAccessService $branchAccess,
    ) {}

    public function show(
        string $order
    ): JsonResponse {
        $tenant =
            $this
            ->currentTenant
            ->get();

        /*
         * Lookup tenant-scoped
         * utilizando UUID público.
         */
        $sale =
            $tenant
            ->orders()
            ->where(
                'public_id',
                $order
            )
            ->firstOrFail();

        Gate::authorize(
            'printTicket',
            $sale
        );
        $sale->loadMissing('branch');
        $this->branchAccess->assertHistory(request()->user(), $tenant, $sale->branch);

        return response()->json([
            'data' =>
            $this
                ->tickets
                ->forOrder(
                    tenant: $tenant,

                    order: $sale,
                ),
        ]);
    }
}
