<?php

namespace App\Services\Fiscal;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Services\Subscriptions\EntitlementService;
use Illuminate\Support\Str;

final class FiscalTicketService
{
    private const ACCESS_CODE_LENGTH = 12;

    private const ACCESS_CODE_ALPHABET =
    '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly CsdVault $vault,
    ) {}

    public function issueForSale(
        Tenant $tenant,
        Order $order,
    ): ?Ticket {
        if (
            ! config(
                'fiscal.enabled'
            )
        ) {
            return null;
        }

        $subscription =
            $this->entitlements
            ->current(
                $tenant
            );

        $eligible =
            $subscription
            &&
            (
                $this->entitlements
                    ->resolve(
                        $subscription,
                        $order->paid_at
                    )['allows_invoicing']
                ?? false
            );

        if (
            ! $eligible
        ) {
            return null;
        }

        $existing =
            Ticket::query()
            ->where(
                'order_id',
                $order->id
            )
            ->first();

        if (
            $existing
        ) {
            return $existing;
        }

        $token =
            Str::random(
                64
            );

        $accessCode =
            $this->generateUniqueAccessCode();

        return Ticket::query()
            ->create([
                'order_id' =>
                $order->id,

                'public_id' =>
                (string) Str::uuid(),

                'token_hash' =>
                hash(
                    'sha256',
                    $token
                ),

                'encrypted_token' =>
                $this->vault
                    ->encryptSecret(
                        $token
                    ),

                'access_code_hash' =>
                hash(
                    'sha256',
                    $accessCode
                ),

                'encrypted_access_code' =>
                $this->vault
                    ->encryptSecret(
                        $accessCode
                    ),

                'fiscal_eligible' =>
                true,

                'expires_at' =>
                $order
                    ->paid_at
                    ->copy()
                    ->addDays(
                        (int) config(
                            'fiscal.ticket_lifetime_days'
                        )
                    ),
            ]);
    }

    public function publicUrl(
        Ticket $ticket,
    ): string {
        $token =
            $this->vault
            ->decryptSecret(
                $ticket->encrypted_token
            );

        return $this->entryUrl()
            . '/'
            . rawurlencode(
                $token
            );
    }

    public function entryUrl(): string
    {
        return rtrim(
            (string) config(
                'app.frontend_url'
            ),
            '/'
        )
            . '/factura';
    }

    public function accessCode(
        Ticket $ticket,
    ): ?string {
        if (
            ! $ticket->encrypted_access_code
        ) {
            return null;
        }

        return $this->vault
            ->decryptSecret(
                $ticket->encrypted_access_code
            );
    }

    public function formattedAccessCode(
        Ticket $ticket,
    ): ?string {
        $code =
            $this->accessCode(
                $ticket
            );

        if (
            $code === null
        ) {
            return null;
        }

        return implode(
            '-',
            str_split(
                $code,
                4
            )
        );
    }

    private function generateUniqueAccessCode(): string
    {
        do {
            $code =
                $this->generateAccessCode();

            $hash =
                hash(
                    'sha256',
                    $code
                );

            $exists =
                Ticket::withoutGlobalScopes()
                ->where(
                    'access_code_hash',
                    $hash
                )
                ->exists();
        } while (
            $exists
        );

        return $code;
    }

    private function generateAccessCode(): string
    {
        $alphabet =
            self::ACCESS_CODE_ALPHABET;

        $maxIndex =
            strlen(
                $alphabet
            ) - 1;

        $code =
            '';

        for (
            $position = 0;
            $position < self::ACCESS_CODE_LENGTH;
            $position++
        ) {
            $code .=
                $alphabet[random_int(
                        0,
                        $maxIndex
                    )];
        }

        return $code;
    }
}
