<?php

namespace App\Http\Controllers\Api\Pos;

use App\Exceptions\DomainConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreOrderRequest;
use App\Http\Resources\Pos\OrderResource;
use App\Models\Order;
use App\Services\Pos\OrderService;
use App\Services\Tickets\TicketService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly OrderService $orders,
        private readonly TicketService $tickets,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public function store(
        StoreOrderRequest $request
    ): JsonResponse {
        Gate::authorize(
            'create',
            Order::class
        );

        try {
            $order = $this
                ->orders
                ->checkout(
                    tenant: $this
                        ->currentTenant
                        ->get(),

                    user: $request->user(),

                    data: $request
                        ->validated(),
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
            'data' =>
            new OrderResource(
                $order
            ),
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Ticket
    |--------------------------------------------------------------------------
    */

    public function ticket(
        string $order,
    ): JsonResponse {
        $tenant =
            $this
            ->currentTenant
            ->get();

        $orderModel = $tenant
            ->orders()
            ->where(
                'public_id',
                $order
            )
            ->with([
                'items',
            ])
            ->firstOrFail();

        Gate::authorize(
            'view',
            $orderModel
        );

        $ticket = $this
            ->tickets
            ->build(
                tenant: $tenant,

                order: $orderModel,
            );

        return response()->json([
            'data' =>
            $ticket,
        ]);
    }
}
