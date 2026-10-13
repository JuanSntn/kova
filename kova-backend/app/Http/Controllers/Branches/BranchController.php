<?php

namespace App\Http\Controllers\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\PurchaseAdditionalBranchRequest;
use App\Http\Requests\Branches\CancelAdditionalBranchRequest;
use App\Http\Requests\Branches\StoreBranchRequest;
use App\Http\Requests\Branches\UpdateBranchRequest;
use App\Models\Branch;
use App\Services\Branches\BranchService;
use App\Services\Branches\AdditionalBranchPurchaseService;
use App\Services\Subscriptions\CapacityService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use DomainException;

final class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly AdditionalBranchPurchaseService $additionalBranches,
        private readonly CapacityService $capacity,
        private readonly EntitlementService $entitlements,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            Branch::class,
        );

        $branches =
            Branch::query()
                ->orderBy('name')
                ->get();
        $usage = $this->capacity->usage(
            $this->currentTenant->get(),
            'included_branches',
            $branches->where('billing_enabled', true)->count(),
        );

        return response()->json([
            'data' => $branches
                ->map(
                    fn (Branch $branch): array => $this->data($branch),
                )
                ->values(),
            'meta' => [
                'capabilities' => [
                    'allowsInvoicing' => $this->entitlements->allows(
                        $this->currentTenant->get(),
                        'allows_invoicing',
                    ),
                ],
                'capacity' => [
                    'limit' => $usage['limit'],
                    'used' => $usage['used'],
                    'remaining' => $usage['remaining'],
                    'unlimited' => $usage['unlimited'],
                    'canCreate' => $usage['can_add'],
                    'reason' => $usage['reason'],
                ],
                'additionalBranch' => $this->additionalBranches->summary(
                    $this->currentTenant->get(),
                    $request->user()?->can('billing.manage') ?? false,
                ),
            ],
        ]);
    }

    public function purchaseAdditional(
        PurchaseAdditionalBranchRequest $request,
    ): JsonResponse {
        Gate::authorize('create', Branch::class);

        $operationId = trim((string) $request->header('Idempotency-Key'));
        if ($operationId === '') {
            $operationId = (string) Str::uuid();
        }

        try {
            $result = $this->additionalBranches->purchase(
                $this->currentTenant->get(),
                $operationId,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => $result,
        ], 202);
    }

    public function cancelAdditional(
        CancelAdditionalBranchRequest $request,
    ): JsonResponse {
        try {
            $result = $this->additionalBranches->cancel(
                $this->currentTenant->get(),
                $request->validated('retained_branch_ids', []),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => $result,
        ], 202);
    }

    public function show(
        Branch $branch,
    ): JsonResponse {
        Gate::authorize(
            'view',
            $branch,
        );

        return response()->json([
            'data' => $this->data(
                $branch,
            ),
        ]);
    }

    public function store(
        StoreBranchRequest $request,
    ): JsonResponse {
        Gate::authorize(
            'create',
            Branch::class,
        );

        $branch =
            $this->branches
                ->create(
                    $request->validated(),
                );

        return response()->json(
            [
                'data' => $this->data(
                    $branch,
                ),
            ],
            201,
        );
    }

    public function update(
        UpdateBranchRequest $request,
        Branch $branch,
    ): JsonResponse {
        Gate::authorize(
            'update',
            $branch,
        );

        $branch =
            $this->branches
                ->update(
                    $branch,
                    $request->validated(),
                );

        return response()->json([
            'data' => $this->data(
                $branch,
            ),
        ]);
    }

    public function enable(Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $branch = $this->branches->enableForPlan($branch);

        return response()->json(['data' => $this->data($branch)]);
    }

    private function data(
        Branch $branch,
    ): array {
        return [
            'id' => $branch->public_id,

            'code' => $branch->code,

            'name' => $branch->name,

            'postalCode' => $branch->postal_code,

            'state' => $branch->state,

            'municipality' => $branch->municipality,

            'locality' => $branch->locality,

            'city' => $branch->city,

            'neighborhood' => $branch->neighborhood,

            'street' => $branch->street,

            'exteriorNumber' => $branch->exterior_number,

            'interiorNumber' => $branch->interior_number,

            'countryCode' => $branch->country_code,

            'timezone' => $branch->timezone,

            'latitude' => $branch->latitude,

            'longitude' => $branch->longitude,

            'phone' => $branch->phone,

            'isActive' => (bool) $branch->is_active,

            'billingEnabled' => (bool) $branch->billing_enabled,

            'fiscalReady' => filled($branch->postal_code)
                && filled($branch->timezone),
        ];
    }
}
