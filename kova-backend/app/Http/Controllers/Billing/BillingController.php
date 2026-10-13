<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCheckoutRequest;
use App\Services\Billing\BillingSummaryService;
use App\Services\Subscriptions\BillingReconciler;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function summary(
        Request $request,
        CurrentTenant $currentTenant,
        BillingSummaryService $summaryService,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingView->value), 403);

        $tenant = $currentTenant->get();

        $summary = $summaryService->build(
            tenant: $tenant,
            user: $request->user(),
        );

        return response()->json($summary->toArray());
    }

    public function checkout(
        CreateCheckoutRequest $request,
        CurrentTenant $currentTenant,
        BillingGateway $billing,
    ): JsonResponse {
        $operationId = trim(
            (string) $request->header(
                'Idempotency-Key'
            )
        );

        if ($operationId === '') {
            $operationId = (string) Str::uuid();
        }

        try {
            $url = $billing->createCheckout(
                tenant: $currentTenant->get(),

                user: $request->user(),

                plan: $request->string('plan')->toString(),
                interval: $request->input('interval', 'month'),
                offer: $request->input('offer_id'),
                retainedBranches: $request->input('retained_branch_ids', []),
                retainedMemberships: $request->input('retained_membership_ids', []),

                operationId: $operationId,
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'url' => $url,
        ], 201);
    }

    public function reconcile(
        Request $request,
        CurrentTenant $currentTenant,
        BillingReconciler $reconciler,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);

        $ok = $reconciler->run($currentTenant->get());

        return response()->json([
            'status' => $ok ? 'reconciled' : 'reconciliation_required',
        ], $ok ? 200 : 202);
    }

    public function cancelPendingCheckout(
        Request $request,
        CurrentTenant $currentTenant,
        BillingReconciler $reconciler,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);

        try {
            $result = $reconciler->cancelPendingCheckout($currentTenant->get());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json($result);
    }

    public function portal(
        Request $request,
        CurrentTenant $currentTenant,
        BillingGateway $billing,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);

        try {
            $url = $billing->createPortal($currentTenant->get());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['url' => $url]);
    }

    public function cancel(
        Request $request,
        CurrentTenant $currentTenant,
        BillingGateway $billing,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);

        try {
            $data = $request->validate([
                'retained_branch_ids' => ['sometimes', 'array'],
                'retained_branch_ids.*' => ['uuid', 'distinct'],
                'retained_membership_ids' => ['sometimes', 'array'],
                'retained_membership_ids.*' => ['uuid', 'distinct'],
            ]);
            $billing->cancelSubscription($currentTenant->get(), $data['retained_branch_ids'] ?? [], $data['retained_membership_ids'] ?? []);

            return response()->json([
                'message' => 'Suscripción cancelada. El acceso se mantendrá hasta el final del periodo actual.',
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function reactivate(
        Request $request,
        CurrentTenant $currentTenant,
        BillingGateway $billing,
    ): JsonResponse {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);

        try {
            $billing->reactivateSubscription($currentTenant->get());

            return response()->json([
                'message' => 'Suscripción reactivada. La cancelación programada ha sido revertida.',
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
