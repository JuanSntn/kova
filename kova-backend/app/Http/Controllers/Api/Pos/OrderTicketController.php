<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Services\Tickets\TicketService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrderTicketController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TicketService $tickets,
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
