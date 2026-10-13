<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Estado actual de invoices.ticket_id
        |--------------------------------------------------------------------------
        |
        | Algunas bases ya tienen eliminada la restricción UNIQUE sobre
        | ticket_id por migraciones anteriores.
        |
        | No podemos asumir que exista un índice llamado:
        |
        | invoices_ticket_id_unique
        |
        | Por eso inspeccionamos primero los índices reales.
        |
        */

        $indexes =
            Schema::getIndexes(
                'invoices'
            );

        $uniqueTicketIndex =
            collect(
                $indexes
            )
            ->first(
                function (
                    array $index
                ): bool {
                    return (
                        $index['columns']
                        ?? []
                    ) === [
                        'ticket_id',
                    ]
                        &&
                        (
                            $index['unique']
                            ?? false
                        ) === true;
                }
            );

        /*
         * Si todavía existe UNIQUE(ticket_id),
         * lo eliminamos.
         */
        if (
            $uniqueTicketIndex !== null
        ) {
            Schema::table(
                'invoices',
                function (
                    Blueprint $table
                ) use (
                    $uniqueTicketIndex
                ): void {
                    $table->dropUnique(
                        $uniqueTicketIndex['name']
                    );
                }
            );
        }

        /*
         * Consultamos nuevamente porque motores como
         * SQLite pueden reconstruir internamente la tabla.
         */
        $indexes =
            Schema::getIndexes(
                'invoices'
            );

        $hasNormalTicketIndex =
            collect(
                $indexes
            )
            ->contains(
                function (
                    array $index
                ): bool {
                    return (
                        $index['columns']
                        ?? []
                    ) === [
                        'ticket_id',
                    ]
                        &&
                        (
                            $index['unique']
                            ?? false
                        ) === false;
                }
            );

        /*
         * InvoiceService consulta invoices por ticket_id.
         *
         * Si ya existe un índice normal, no hacemos nada.
         */
        if (
            ! $hasNormalTicketIndex
        ) {
            Schema::table(
                'invoices',
                function (
                    Blueprint $table
                ): void {
                    $table->index(
                        'ticket_id',
                        'invoices_ticket_id_index'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        /*
         * Antes de restaurar UNIQUE(ticket_id),
         * comprobamos que todavía sea posible.
         *
         * Si ya existe más de una factura para el mismo
         * ticket, restaurar esa restricción destruiría
         * la semántica actual.
         */

        $hasDuplicates =
            DB::table(
                'invoices'
            )
            ->whereNotNull(
                'ticket_id'
            )
            ->select(
                'ticket_id'
            )
            ->groupBy(
                'ticket_id'
            )
            ->havingRaw(
                'COUNT(*) > 1'
            )
            ->exists();

        if (
            $hasDuplicates
        ) {
            throw new \RuntimeException(
                'No se puede restaurar UNIQUE sobre invoices.ticket_id porque existen tickets con múltiples facturas.'
            );
        }

        $indexes =
            Schema::getIndexes(
                'invoices'
            );

        $normalTicketIndex =
            collect(
                $indexes
            )
            ->first(
                function (
                    array $index
                ): bool {
                    return (
                        $index['columns']
                        ?? []
                    ) === [
                        'ticket_id',
                    ]
                        &&
                        (
                            $index['unique']
                            ?? false
                        ) === false;
                }
            );

        if (
            $normalTicketIndex !== null
        ) {
            Schema::table(
                'invoices',
                function (
                    Blueprint $table
                ) use (
                    $normalTicketIndex
                ): void {
                    $table->dropIndex(
                        $normalTicketIndex['name']
                    );
                }
            );
        }

        Schema::table(
            'invoices',
            function (
                Blueprint $table
            ): void {
                $table->unique(
                    'ticket_id',
                    'invoices_ticket_id_unique'
                );
            }
        );
    }
};
