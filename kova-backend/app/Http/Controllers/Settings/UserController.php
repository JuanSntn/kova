<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MembershipStatus;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreStaffUserRequest;
use App\Http\Requests\Settings\UpdateStaffUserRequest;
use App\Models\Membership;
use App\Services\Memberships\MembershipService;
use App\Services\Subscriptions\CapacityService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class UserController extends Controller
{
    public function index(Request $request, CurrentTenant $current, CapacityService $capacity): JsonResponse
    {
        abort_unless($request->user()->can(PermissionName::UsersView->value), 403);
        $tenant = $current->get();
        $memberships = $tenant->memberships()
            ->where('status', '!=', MembershipStatus::Archived->value)
            ->with(['user', 'branches'])
            ->orderBy('id')
            ->get();
        $active = $memberships->where('status', MembershipStatus::Active)->count();
        $usage = $capacity->usage($tenant, 'max_users', $active);
        $previous = getPermissionsTeamId();

        try {
            setPermissionsTeamId($tenant->id);
            $data = $memberships->map(function (Membership $membership): array {
                $membership->user->unsetRelation('roles');
                return [
                    'id' => $membership->public_id,
                    'name' => $membership->user->name,
                    'username' => $membership->login_name,
                    'account_type' => $membership->user->effectiveAccountType()->value,
                    'role' => $membership->user->getRoleNames()->first(),
                    'status' => $membership->status->value,
                    'last_login_at' => $membership->last_login_at,
                    'branches' => $membership->branches->map(fn ($branch) => ['id' => $branch->public_id, 'name' => $branch->name])->values(),
                ];
            })->values();
        } finally {
            setPermissionsTeamId($previous);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'users' => [
                    'current' => $active,
                    'limit' => $usage['limit'],
                    'unlimited' => $usage['unlimited'],
                    'remaining' => $usage['remaining'],
                    'can_create' => $usage['can_add'],
                ],
                'staff_login' => [
                    'branches' => $tenant->branches()->where('is_active', true)->where('billing_enabled', true)->orderBy('name')->get()->map(fn ($branch) => [
                        'id' => $branch->public_id,
                        'name' => $branch->name,
                        'url' => rtrim((string) config('app.frontend_url'), '/').'/login/'.$branch->staff_login_code,
                    ])->values(),
                ],
            ],
        ]);
    }

    public function store(StoreStaffUserRequest $request, CurrentTenant $current, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $membership = $service->createStaff($current->get(), $request->validated());
        return response()->json(['data' => ['id' => $membership->public_id]], 201);
    }

    public function update(UpdateStaffUserRequest $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $membership = $service->updateStaff($membership, $request->validated());
        return response()->json(['data' => ['id' => $membership->public_id]]);
    }

    public function suspend(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $service->suspend($membership);
        return response()->json(['message' => 'Usuario suspendido.']);
    }

    public function activate(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $service->activate($membership);
        return response()->json(['message' => 'Usuario reactivado.']);
    }

    public function destroy(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $service->removeStaff($membership);

        return response()->json(['message' => 'Usuario eliminado del equipo.']);
    }

    public function branches(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['branch_ids' => ['required', 'array', 'min:1'], 'branch_ids.*' => ['uuid', 'distinct']]);
        $updated = $service->syncBranches($membership, $data['branch_ids']);
        return response()->json(['data' => ['id' => $updated->public_id, 'branch_ids' => $updated->branches->pluck('public_id')->values()]]);
    }

    public function password(Request $request, Membership $membership, MembershipService $service): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(
            ['password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]],
            [
                'password.required' => 'La contraseña es obligatoria.',
                'password.confirmed' => 'La confirmación de contraseña no coincide.',
                'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
                'password.mixed' => 'La contraseña debe incluir al menos una letra mayúscula y una minúscula.',
                'password.numbers' => 'La contraseña debe incluir al menos un número.',
            ],
        );
        $service->resetPassword($membership, $data['password']);
        return response()->json(['message' => 'Contraseña actualizada y sesiones cerradas.']);
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->can(PermissionName::UsersManage->value), 403);
    }
}
