<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ListSalesRequest;
use App\Http\Resources\Pos\SaleDetailResource;
use App\Http\Resources\Pos\SaleListResource;
use App\Models\Order;
use App\Services\Sales\SalesHistoryService;
use App\Tenancy\CurrentTenant;
use App\Tenancy\CurrentBranch;
use App\Services\Branches\BranchAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SalesHistoryController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SalesHistoryService $sales,
        private readonly CurrentBranch $currentBranch,
        private readonly BranchAccessService $branchAccess,
    ) {}

    public function index(
        ListSalesRequest $request
    ): JsonResponse {
        Gate::authorize(
            'viewAny',
            Order::class,
        );

        $paginator = $this
            ->sales
            ->paginate(
                tenant: $this
                    ->currentTenant
                    ->get(),

                branch: $this->currentBranch->get(),

                filters: $request->validated(),
            );

        return response()->json([
            'data' =>
            SaleListResource::collection(
                $paginator->items()
            ),

            'meta' => [
                'current_page' =>
                $paginator->currentPage(),

                'last_page' =>
                $paginator->lastPage(),

                'per_page' =>
                $paginator->perPage(),

                'total' =>
                $paginator->total(),
            ],
        ]);
    }

    public function show(
        string $sale
    ): JsonResponse {
        $order = $this
            ->sales
            ->find(
                tenant: $this
                    ->currentTenant
                    ->get(),

                publicId: $sale,
            );

        Gate::authorize(
            'view',
            $order,
        );

        $order->loadMissing('branch');
        $this->branchAccess->assertHistory(request()->user(), $this->currentTenant->get(), $order->branch);

        return response()->json([
            'data' =>
            new SaleDetailResource(
                $order
            ),
        ]);
    }
}
