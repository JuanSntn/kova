<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Actions\RegisterTenant;
use App\Enums\MembershipStatus;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        RegisterTenant $registerTenant,
    ): JsonResponse {
        ['user' => $user, 'tenant' => $tenant] = $registerTenant->handle(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            businessName: $request->string('business_name')->toString(),
        );

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'message' => 'Cuenta creada. Revisa tu correo para verificarla antes de iniciar sesión.',
            'email' => $user->email,
            'tenant' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['El correo o la contraseña no son correctos.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión.',
                'code' => 'email_unverified',
                'email' => $user->email,
            ], 403);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return response()->json($this->sessionPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->sessionPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(User $user): array
    {
        $tenants = $user->tenants()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('tenants.status', 'active')
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.public_id', 'tenants.name', 'tenants.slug']);

        $previousPermissionsTenantId = getPermissionsTeamId();

        try {
            $tenantPayload = $tenants->map(function ($tenant) use ($user) {
                setPermissionsTeamId($tenant->getKey());
                $user->unsetRelation('roles');
                $user->unsetRelation('permissions');

                $abilities = collect(PermissionName::cases())->filter(
                    fn (PermissionName $permission) => $user->can($permission->value),
                )->map(
                    fn (PermissionName $permission) => $permission->value,
                )->values();

                return [
                    'id' => $tenant->public_id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'abilities' => $abilities,
                ];
            })->values();

            return [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ],
                'tenants' => $tenantPayload,
            ];
        } finally {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            setPermissionsTeamId($previousPermissionsTenantId);
        }
    }
}
