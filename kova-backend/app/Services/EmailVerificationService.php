<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationService
{
    public function remember(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('email_verification', [
                'user_id' => $user->id,
                'email' => $user->email,
                'credential_fingerprint' => $user->verificationCredentialFingerprint(),
                'expires_at' => now()->addHour()->timestamp,
            ]);
        }
    }

    public function pendingUser(Request $request): ?User
    {
        $pending = $request->hasSession() ? $request->session()->get('email_verification') : null;
        if (! $pending || $pending['expires_at'] <= now()->timestamp) {
            return null;
        }

        $user = User::query()->whereKey($pending['user_id'])->where('email', $pending['email'])->first();

        return $user && hash_equals($user->verificationCredentialFingerprint(), $pending['credential_fingerprint'] ?? '') ? $user : null;
    }

    public function retryAfter(User $user): int
    {
        return RateLimiter::availableIn('verification-send:'.$user->id);
    }

    public function send(User $user): array
    {
        // Serialize sends across sessions/IPs; the browser countdown is only UX.
        $lock = Cache::lock('verification-send-lock:'.$user->id, 60);
        if (! $lock->get()) {
            return ['delivery' => 'throttled', 'retry_after' => 60];
        }

        try {
            if ($this->retryAfter($user) > 0) {
                return ['delivery' => 'throttled', 'retry_after' => $this->retryAfter($user)];
            }
            RateLimiter::hit('verification-send:'.$user->id, 60);
            $user->sendEmailVerificationNotification();

            return [
                'delivery' => in_array(config('mail.default'), ['log', 'array'], true) ? 'local' : 'submitted',
                'retry_after' => 60,
            ];
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Verification mail failed', ['exception_type' => get_class($exception)]);

            return ['delivery' => 'failed', 'retry_after' => $this->retryAfter($user)];
        } finally {
            $lock->release();
        }
    }

    public function message(string $delivery): string
    {
        return match ($delivery) {
            'local' => 'El envío de correos está en modo de prueba. Este mensaje no llegará a tu bandeja de entrada.',
            'submitted' => 'Correo enviado. Revisa tu bandeja de entrada y la carpeta de spam.',
            'failed' => 'No pudimos enviar el correo. Espera un minuto y vuelve a intentarlo.',
            default => 'Espera un momento antes de solicitar otro correo.',
        };
    }
}
