<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StoreOrderRequest;
use App\Http\Requests\Pos\QuoteOrderRequest;
use App\Http\Resources\Pos\OrderResource;
use App\Models\Order;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\CheckoutQuoteService;
use App\Services\Subscriptions\CapacityService;
use App\Tenancy\CurrentTenant;
use App\Tenancy\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function __construct(private CurrentTenant $tenants, private CheckoutService $checkout) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $result = $this->checkout->confirm($this->tenants->get(), $request->user(), $key, $request->header('X-Branch-ID'), $data);
        return response()->json(['data' => new OrderResource($result['order'])], $result['created'] ? 201 : 200);
    }

    public function quote(QuoteOrderRequest $request, CheckoutQuoteService $quotes, CurrentBranch $branches, CapacityService $capacity): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $tenant = $this->tenants->get();
        $capacity->assertFeature($tenant, 'allows_counter_orders');
        return response()->json(['data' => $quotes->prepare($tenant, $branches->get(), $request->user(), $request->validated('items'))]);
    }

    public function recover(Request $request, string $idempotencyKey): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $order = $this->checkout->recover($this->tenants->get(), $request->user(), $idempotencyKey);
        if (! $order) {
            return response()->json(['message' => 'No hay un resultado confirmado. Conserva la misma clave para reintentar.', 'code' => 'POS_CHECKOUT_NOT_FOUND'], 404);
        }
        return response()->json(['data' => new OrderResource($order)]);
    }
}
