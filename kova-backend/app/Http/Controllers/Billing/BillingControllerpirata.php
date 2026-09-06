<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Enums\PermissionName;
use App\Enums\PlanCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCheckoutRequest;
use App\Services\Billing\BillingSummaryService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingControllerpirata extends Controller
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
        try {
            $url = $billing->createCheckout(
                $currentTenant->get(),
                $request->user(),
                PlanCode::from($request->string('plan')->toString()),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['url' => $url], 201);
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
            $billing->cancelSubscription($currentTenant->get());

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
