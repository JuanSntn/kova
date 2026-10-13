<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Actions\RegisterTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\Auth\SessionPayloadService;
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
        EmailVerificationService $verification,
    ): JsonResponse {
        ['user' => $user, 'tenant' => $tenant] = $registerTenant->handle(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            businessName: $request->string('business_name')->toString(),
        );

        $verification->remember($request, $user);
        $delivery = $verification->send($user);
        if ($request->hasSession()) {
            $request->session()->put('email_verification.delivery', $delivery['delivery']);
        }

        return response()->json([
            'message' => 'Cuenta creada. '.$verification->message($delivery['delivery']),
            'delivery' => $delivery['delivery'],
            'email' => $user->email,
            'tenant' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
            ],
        ], 201);
    }

    public function login(LoginRequest $request, EmailVerificationService $verification, SessionPayloadService $payloads): JsonResponse
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
            $verification->remember($request, $user);
            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión.',
                'code' => 'email_unverified',
                'email' => $user->email,
            ], 403);
        }

        $request->session()->forget('email_verification');
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return response()->json($payloads->build($user));
    }

    public function me(Request $request, SessionPayloadService $payloads): JsonResponse
    {
        return response()->json($payloads->build($request->user()));
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

}
