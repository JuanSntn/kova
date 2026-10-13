<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $accountType = $user->effectiveAccountType();

        if ($accountType === AccountType::Email) {
            abort_unless($user->hasVerifiedEmail(), 403, 'Debes verificar tu correo antes de continuar.');
        } else {
            abort_unless(
                $accountType === AccountType::TenantLocal
                && $user->email === null
                && $user->memberships()->where('status', MembershipStatus::Active->value)->exists(),
                403,
                'La cuenta no está disponible.',
            );
        }

        return $next($request);
    }
}
