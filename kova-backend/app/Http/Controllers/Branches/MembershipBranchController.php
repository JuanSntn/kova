<?php

namespace App\Http\Controllers\Branches;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MembershipBranchController extends Controller
{
    public function update(Request $request, Membership $membership, CurrentTenant $current): JsonResponse
    {
        abort_unless($request->user()->hasRole(RoleName::Owner->value), 403);
        $tenant = $current->get();
        abort_unless((int) $membership->tenant_id === (int) $tenant->id && $membership->status === MembershipStatus::Active, 404);
        $data = $request->validate(['branch_ids' => ['required', 'array'], 'branch_ids.*' => ['uuid', 'distinct']]);
        $branches = $tenant->branches()->whereIn('public_id', $data['branch_ids'])->get();
        abort_unless($branches->count() === count($data['branch_ids']), 422);
        DB::transaction(fn () => $membership->branches()->sync($branches->modelKeys()));

        return response()->json(['data' => ['membership_id' => $membership->public_id, 'branch_ids' => $branches->pluck('public_id')->values()]]);
    }
}
