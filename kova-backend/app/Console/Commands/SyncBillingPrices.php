<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\StripeCatalogSyncService;
use Illuminate\Console\Command;
use Throwable;

final class SyncBillingPrices extends Command
{
    /*
    |--------------------------------------------------------------------------
    | Command
    |--------------------------------------------------------------------------
    |
    | Conservamos billing:sync-prices porque ya existía
    | dentro de Kova.
    |
    | También damos el alias:
    |
    | billing:sync-stripe-catalog
    |
    */

    protected $signature = 'billing:sync-prices
        {--test : Sincronizar contra Stripe test}
        {--live : Sincronizar contra Stripe live}
        {--dry-run : Mostrar el catálogo local sin escribir en Stripe}';

    protected $aliases = [
        'billing:sync-stripe-catalog',
    ];

    protected $description =
    'Sincroniza Prices, Billing Meters y mappings de Kova con Stripe sin modificar el catálogo comercial';

    public function handle(
        StripeCatalogSyncService $sync
    ): int {
        $dryRun =
            (bool) $this->option(
                'dry-run'
            );

        $test =
            (bool) $this->option(
                'test'
            );

        $live =
            (bool) $this->option(
                'live'
            );

        /*
        |--------------------------------------------------------------------------
        | Safety
        |--------------------------------------------------------------------------
        */

        if (! $dryRun) {
            /*
             * Debes elegir exactamente uno.
             */
            if (
                $test
                === $live
            ) {
                $this->error(
                    'Indica exactamente uno de --test o --live.'
                );

                return self::FAILURE;
            }

            $configuredLive =
                (bool) config(
                    'billing.livemode',
                    false
                );

            /*
             * La intención del comando debe coincidir
             * con el modo configurado por Kova.
             */
            if (
                $configuredLive
                !== $live
            ) {
                $this->error(
                    $configuredLive
                        ? 'BILLING_LIVEMODE=true: ejecuta el comando con --live.'
                        : 'BILLING_LIVEMODE=false: ejecuta el comando con --test.'
                );

                return self::FAILURE;
            }

            /*
             * También verificamos que la API key tenga
             * el prefijo correcto.
             *
             * Nunca imprimimos la clave.
             */
            $secret =
                (string) config(
                    'cashier.secret'
                );

            $secretMatches =
                $live
                ? (
                    str_starts_with(
                        $secret,
                        'sk_live_'
                    )
                    || str_starts_with(
                        $secret,
                        'rk_live_'
                    )
                )
                : (
                    str_starts_with(
                        $secret,
                        'sk_test_'
                    )
                    || str_starts_with(
                        $secret,
                        'rk_test_'
                    )
                );

            if (
                ! $secretMatches
            ) {
                $this->error(
                    'La clave secreta de Stripe no corresponde al modo solicitado.'
                );

                return self::FAILURE;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Run
        |--------------------------------------------------------------------------
        */

        try {
            $rows =
                $dryRun
                ? $sync->preview()
                : $sync->sync();
        } catch (
            Throwable $exception
        ) {
            $this->error(
                $exception
                    ->getMessage()
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Output
        |--------------------------------------------------------------------------
        */

        $this->newLine();

        $this->info(
            $dryRun
                ? 'Kova billing catalog · DRY RUN'
                : 'Kova billing catalog · '
                . (
                    $live
                    ? 'LIVE'
                    : 'TEST'
                )
        );

        $this->newLine();

        $this->table(
            [
                'Tipo',
                'Código',
                'Versión',
                'Intervalo',
                'Precio',
                'Stripe Price',
                'Resultado',
            ],
            array_map(
                fn(
                    array $row
                ) => [
                    $row['kind'] === 'plan'
                        ? 'Plan'
                        : 'Addon',

                    $row['code'],

                    'v'
                        . $row['version'],

                    $row['interval'],

                    $this->money(
                        $row['amount'],

                        $row['currency']
                    ),

                    $row['stripe_price_id']
                        ?? '—',

                    $this->actionLabel(
                        $row['action']
                    ),
                ],
                $rows
            )
        );

        $mapped =
            collect(
                $rows
            )
            ->whereNotNull(
                'stripe_price_id'
            )
            ->count();

        $localOnly =
            collect(
                $rows
            )
            ->where(
                'action',
                'local_only'
            )
            ->count();

        $this->newLine();

        if ($dryRun) {
            $this->line(
                'No se realizaron cambios en Stripe ni en billing_price_mappings.'
            );
        } else {
            $this->info(
                $mapped
                    . ' mappings Stripe listos.'
            );

            if (
                $localOnly > 0
            ) {
                $this->line(
                    $localOnly
                        . ' oferta(s) local(es) no requieren Stripe.'
                );
            }
        }

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    */

    private function money(
        int $amount,
        string $currency
    ): string {
        return number_format(
            $amount / 100,
            2,
            '.',
            ','
        )
            . ' '
            . strtoupper(
                $currency
            );
    }

    private function actionLabel(
        string $action
    ): string {
        return match ($action) {
            'local_only' =>
            'Local solamente',

            'pending_sync' =>
            'Pendiente',

            'created' =>
            'Creado',

            'linked' =>
            'Recuperado',

            'adopted' =>
            'Mapping existente adoptado',

            'verified' =>
            'Verificado',

            default =>
            $action,
        };
    }
}
