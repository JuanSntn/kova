<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Enums\AccountType;
use App\Services\Auth\SessionInvalidator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            // Serialize token issuance and consumption on the same user row.
            User::where('account_type', AccountType::Email->value)->where('email', $request->validated('email'))->lockForUpdate()->first();
            try {
                Password::sendResetLink($request->safe()->only('email'));
            } catch (\Throwable $exception) {
                // Transport errors can contain credentials or message bodies. Never report them verbatim.
                Log::error('Password recovery mail failed', ['exception_type' => get_class($exception)]);
            }
        });

        return response()->json([
            'message' => 'Si existe una cuenta con ese correo, recibirás un enlace para restablecer tu contraseña. Revisa también spam.',
            'retry_after' => 60,
            'local_delivery' => in_array(config('mail.default'), ['log', 'array'], true),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function reset(ResetPasswordRequest $request, SessionInvalidator $sessions): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $sessions) {
            User::where('account_type', AccountType::Email->value)->where('email', $request->validated('email'))->lockForUpdate()->first();

            return Password::reset($request->validated(), function (User $user, string $password) use ($sessions) {
                abort_unless($user->account_type === AccountType::Email, 422);
                $user->forceFill(['password' => $password])->save();
                $sessions->invalidate($user);
                event(new PasswordReset($user));
            });
        }, 3);

        if ($result !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'El enlace no es válido, ya fue utilizado o ha vencido. Solicita uno nuevo.',
                'code' => 'invalid_reset_token',
            ], 422);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión con tu nueva contraseña.']);
    }
}
