<?php

namespace App\Http\Controllers\Billing;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\OveragePolicyService;
use App\Services\Fiscal\StampUsageSummaryService;
use App\Tenancy\CurrentTenant;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StampUsageController extends Controller
{
    public function show(Request $request, CurrentTenant $currentTenant, StampUsageSummaryService $summary): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::BillingView->value), 403);

        return response()->json($summary->for($currentTenant->get()));
    }

    public function update(Request $request, CurrentTenant $currentTenant, OveragePolicyService $policies, StampUsageSummaryService $summary): JsonResponse
    {
        abort_unless($request->user()?->can(PermissionName::BillingManage->value), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            $policies->set($currentTenant->get(), $request->user(), (bool) $data['enabled'], $request);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($summary->for($currentTenant->get()));
    }
}
