<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EmailVerificationController extends Controller
{
    public function verify(int $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(
            hash_equals($hash, sha1($user->getEmailForVerification())),
            403,
            'El enlace de verificación no es válido.',
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away(
            rtrim((string) config('app.frontend_url'), '/').'/email-verified',
        );
    }

    public function status(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $user = $verification->pendingUser($request);

        return response()->json($user ? [
            'status' => $user->hasVerifiedEmail() ? 'verified' : 'pending',
            'email' => $user->email,
            'retry_after' => $verification->retryAfter($user),
            'message' => ($delivery = $request->session()->get('email_verification.delivery'))
                ? $verification->message($delivery) : null,
        ] : ['status' => 'unavailable'])->header('Cache-Control', 'no-store, private');
    }

    public function resend(ResendVerificationRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();
        $pending = $verification->pendingUser($request);
        $ownsRequest = $pending && $user && $pending->is($user);
        $generic = ['message' => 'Si la cuenta existe y está pendiente, enviaremos un nuevo enlace.'];

        if (! $user || $user->hasVerifiedEmail()) {
            return response()->json($ownsRequest ? [
                'message' => 'Tu correo ya está verificado. Puedes iniciar sesión.',
                'status' => 'verified',
            ] : $generic);
        }

        $delivery = $verification->send($user);
        if (! $ownsRequest) {
            return response()->json($generic);
        }

        if ($delivery['delivery'] !== 'throttled') {
            $request->session()->put('email_verification.delivery', $delivery['delivery']);
        }
        $status = match ($delivery['delivery']) {
            'failed' => 503,
            'throttled' => 429,
            default => 200,
        };

        return response()->json([
            ...$delivery,
            'message' => $verification->message($delivery['delivery']),
        ], $status)->header('Retry-After', (string) $delivery['retry_after']);
    }
}
