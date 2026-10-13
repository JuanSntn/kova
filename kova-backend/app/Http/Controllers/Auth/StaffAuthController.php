<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Models\Branch;
use App\Models\Membership;
use App\Services\Auth\SessionPayloadService;
use App\Services\Memberships\MembershipService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class StaffAuthController extends Controller
{
    public function show(string $branchLoginCode): JsonResponse
    {
        $branch = $this->operableBranch($branchLoginCode);
        abort_unless($branch, 404);

        return response()->json(['data' => [
            'business' => $branch->tenant->name,
            'branch' => $branch->name,
        ]]);
    }

    public function login(
        StaffLoginRequest $request,
        string $branchLoginCode,
        CurrentTenant $current,
        SessionPayloadService $payloads,
    ): JsonResponse {
        $branch = $this->operableBranch($branchLoginCode);
        if (! $branch) {
            return $this->invalidCredentials();
        }

        return $current->run($branch->tenant, function () use ($request, $branch, $payloads): JsonResponse {
            $membership = Membership::query()
                ->where('login_name_normalized', MembershipService::normalizeLoginName($request->validated('username')))
                ->where('status', MembershipStatus::Active->value)
                ->with('user')
                ->first();

            $valid = $membership
                && $membership->user->account_type === AccountType::TenantLocal
                && Hash::check($request->validated('password'), $membership->user->password)
                && $membership->branches()->whereKey($branch->id)->exists();

            if (! $valid) {
                return $this->invalidCredentials();
            }

            Auth::guard('web')->login($membership->user, false);
            $request->session()->regenerate();
            Auth::forgetGuards();
            $membership->forceFill(['last_login_at' => now()])->save();

            return response()->json($payloads->build($membership->user, $branch->public_id));
        });
    }

    private function operableBranch(string $code): ?Branch
    {
        return Branch::withoutGlobalScopes()
            ->with('tenant')
            ->where('staff_login_code', strtoupper(trim($code)))
            ->where('is_active', true)
            ->where('billing_enabled', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->first();
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json([
            'message' => 'Usuario o contraseña incorrectos.',
            'code' => 'invalid_credentials',
        ], 422);
    }
}
