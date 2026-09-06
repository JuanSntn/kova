<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(
        User $user
    ): bool {
        return $user->can(
            PermissionName::OrdersView->value
        );
    }

    public function view(
        User $user,
        Order $order,
    ): bool {
        return $user->can(
            PermissionName::OrdersView->value
        );
    }

    public function create(
        User $user
    ): bool {
        return $user->can(
            PermissionName::OrdersCreate->value
        );
    }

    public function printTicket(
        User $user,
        Order $order,
    ): bool {
        /*
         * Quien puede consultar ventas
         * puede reimprimir tickets.
         */
        if (
            $user->can(
                PermissionName::OrdersView->value
            )
        ) {
            return true;
        }

        /*
         * Quien está operando el POS
         * puede imprimir la venta
         * que él mismo acaba de realizar.
         *
         * Esto permite al Cajero imprimir
         * sin darle acceso completo
         * al historial de ventas.
         */
        return
            $user->can(
                PermissionName::OrdersCreate->value
            )
            &&
            (int) $order->created_by ===
            (int) $user->getKey();
    }
}
