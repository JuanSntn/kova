<?php

namespace App\Http\Controllers\Branches;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Services\Branches\BranchAccessService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BranchContextController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, BranchAccessService $access): JsonResponse
    {
        $permission = $request->user()->can(PermissionName::OrdersView->value)
            ? PermissionName::OrdersView->value
            : PermissionName::OrdersCreate->value;
        $branches = $access->operableFor($request->user(), $current->get(), $permission);

        return response()->json(['data' => $branches->map(fn ($branch) => [
            'id' => $branch->public_id, 'code' => $branch->code, 'name' => $branch->name, 'timezone' => $branch->timezone,
        ])->values()]);
    }
}
