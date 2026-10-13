<?php

namespace App\Services\Pos;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

final class CheckoutQuoteService
{
    public function __construct(private OrderCalculator $calculator) {}

    public function prepare(Tenant $tenant, Branch $branch, User $user, array $items): array
    {
        $calculation = $this->calculator->calculate($tenant, $branch, $items);
        $cash = app(\App\Services\Cash\CashService::class)->saleContext($tenant, $branch, $user);
        $expires = now()->addMinutes(5)->timestamp;
        $token = Crypt::encryptString(json_encode([
            'tenant' => $tenant->id, 'branch' => $branch->id, 'user' => $user->id,
            'items' => $items, 'fingerprint' => $this->calculator->fingerprint($calculation),
            'expires' => $expires, 'cash' => $cash,
        ], JSON_THROW_ON_ERROR));
        $lines = array_map(fn ($line, $item) => [
            ...$line, 'product_id' => $item['product_id'],
        ], $calculation['lines'], $items);
        return [...$calculation, 'lines' => $lines, 'quote_token' => $token, 'expires_at' => $expires];
    }

    public function verify(string $token, Tenant $tenant, Branch $branch, User $user, array $items, array $calculation): void
    {
        try {
            $quote = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DomainConflictException('Actualiza el resumen antes de confirmar.', 'POS_QUOTE_INVALID');
        }
        if (($quote['tenant'] ?? null) !== $tenant->id || ($quote['branch'] ?? null) !== $branch->id
            || ($quote['user'] ?? null) !== $user->id || ($quote['items'] ?? null) !== $items) {
            throw new DomainConflictException('El resumen no corresponde a esta compra.', 'POS_QUOTE_INVALID');
        }
        if (($quote['expires'] ?? 0) <= now()->timestamp) {
            throw new DomainConflictException('El resumen venció. Actualízalo y confirma nuevamente.', 'POS_QUOTE_EXPIRED');
        }
        if (($quote['cash'] ?? null) !== app(\App\Services\Cash\CashService::class)->saleContext($tenant, $branch, $user)) {
            throw new DomainConflictException('Cambió el turno de caja. Prepara nuevamente el cobro.', 'CASH_QUOTE_CHANGED');
        }
        if (! hash_equals($quote['fingerprint'], $this->calculator->fingerprint($calculation))) {
            throw new DomainConflictException('Los precios o impuestos cambiaron. Revisa el nuevo resumen antes de confirmar.', 'POS_QUOTE_CHANGED');
        }
    }
}

