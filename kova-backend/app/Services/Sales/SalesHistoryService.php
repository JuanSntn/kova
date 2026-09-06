<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class SalesHistoryService
{
    public function paginate(
        Tenant $tenant,
        array $filters,
    ): LengthAwarePaginator {
        $query = $tenant
            ->orders()
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        /*
         * Buscar por folio.
         */
        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        if ($search !== '') {
            $query->where(
                'folio',
                'like',
                "%{$search}%"
            );
        }

        /*
         * Método de pago.
         */
        if (! empty($filters['payment_method'])) {
            $query->where(
                'payment_method',
                $filters['payment_method'],
            );
        }

        $timezone = $tenant->timezone
            ?: config('app.timezone', 'UTC');

        /*
         * Desde:
         *
         * 2026-08-10 00:00 horario local
         * convertido a UTC.
         */
        if (! empty($filters['date_from'])) {
            $from = Carbon::createFromFormat(
                'Y-m-d',
                $filters['date_from'],
                $timezone,
            )
                ->startOfDay()
                ->utc();

            $query->where(
                'paid_at',
                '>=',
                $from,
            );
        }

        /*
         * Hasta:
         *
         * Usamos el inicio del día siguiente
         * como límite exclusivo.
         */
        if (! empty($filters['date_to'])) {
            $to = Carbon::createFromFormat(
                'Y-m-d',
                $filters['date_to'],
                $timezone,
            )
                ->addDay()
                ->startOfDay()
                ->utc();

            $query->where(
                'paid_at',
                '<',
                $to,
            );
        }

        $perPage = (int) (
            $filters['per_page']
            ?? 20
        );

        return $query
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(
        Tenant $tenant,
        string $publicId,
    ): Order {
        return $tenant
            ->orders()
            ->where(
                'public_id',
                $publicId,
            )
            ->where(
                'status',
                'paid',
            )
            ->with([
                'items',
            ])
            ->firstOrFail();
    }
}
