<?php

namespace App\Services\Pos;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\PlanLimitService;
use App\Services\Subscriptions\CapacityService;
use App\Services\Subscriptions\CatalogAccessService;
use App\Services\Taxes\ProductTaxService;
use App\Services\Tickets\TicketSettingsService;
use App\Services\Fiscal\FiscalTicketService;
use App\Services\Branches\BranchProductService;
use App\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\DB;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class OrderService
{
    public function __construct(
        private readonly ProductTaxService $taxes,
        private readonly PlanLimitService $limits,
        private readonly TicketSettingsService $ticketSettings,
        private readonly CatalogAccessService $catalogAccess,
        private readonly FiscalTicketService $fiscalTickets,
        private readonly BranchProductService $branchProducts,
        private readonly CurrentBranch $currentBranch,
    ) {}

    public function checkout(
        Tenant $tenant,
        User $user,
        array $data,
    ): Order {
        return DB::transaction(function () use (
            $tenant,
            $user,
            $data
        ): Order {
            /*
        |--------------------------------------------------------------------------
        | Sucursal
        |--------------------------------------------------------------------------
        */

            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(CapacityService::class)->assertFeature($tenant, 'allows_counter_orders');
            $branch = $this->currentBranch->get();

            $calculation = app(OrderCalculator::class)->calculate($tenant, $branch, $data['items']);
            if (isset($data['quote_token'])) {
                app(CheckoutQuoteService::class)->verify($data['quote_token'], $tenant, $branch, $user, $data['items'], $calculation);
            }
            $lineSnapshots = $calculation['lines'];
            $subtotal = $calculation['subtotal'];
            $taxBaseTotal = $calculation['tax_base_total'];
            $taxTotal = $calculation['tax_total'];

            /*
         * Por ahora:
         *
         * - sin descuentos
         * - sin propinas
         * - sin cargos por servicio
         *
         * El precio ya incluye impuestos.
         */
            $total = $subtotal;

            /*
        |--------------------------------------------------------------------------
        | Pago en efectivo
        |--------------------------------------------------------------------------
        */

            $amountReceived = null;
            $changeAmount = null;

            if (
                $data['payment_method'] ===
                'cash'
            ) {
                /*
             * StoreOrderRequest ya debe exigir
             * amount_received para efectivo.
             */
                $amountReceived = $this->money(
                    (string) $data['amount_received']
                );

                /*
             * Segunda validación a nivel de dominio.
             *
             * Aunque el frontend ya valida esto,
             * Laravel es la autoridad.
             */
                if (
                    BigDecimal::of($amountReceived)->isLessThan($total)
                ) {
                    throw new DomainConflictException(
                        'El efectivo recibido es menor al total de la venta.',
                        'INSUFFICIENT_CASH_RECEIVED',
                    );
                }

                /*
             * El cambio se calcula únicamente
             * en backend.
             */
                $changeAmount = $this->money(
                    (string) BigDecimal::of($amountReceived)->minus($total)
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Límite mensual del plan
        |--------------------------------------------------------------------------
        */

            $this->limits
                ->assertCanAddMonthlySales(
                    tenant: $tenant,

                    newSaleTotal: $total,
                );

            /*
        |--------------------------------------------------------------------------
        | Crear orden
        |--------------------------------------------------------------------------
        |
        | Primero necesitamos insertar la orden para
        | que MySQL genere su ID autoincremental.
        |
        | Después usamos ese ID para construir:
        |
        | KVA-000001
        | KVA-000002
        | ...
        |
        */

            $order = $tenant
                ->orders()
                ->create([
                    'branch_id' => $branch->id,

                    /*
                 * Se genera después usando
                 * $order->id.
                 */
                    'folio' => null,

                    'order_type' => 'counter',

                    'status' => 'paid',

                    'subtotal' => $subtotal,

                    'tax_base_total' => $taxBaseTotal,

                    'tax_total' => $taxTotal,

                    'discount_total' => 0,

                    'service_charge_total' => 0,

                    'tip_total' => 0,

                    'total' => $total,

                    'payment_method' => $data['payment_method'],

                    'amount_received' => $amountReceived,

                    'change_amount' => $changeAmount,

                    'paid_at' => now(),

                    'created_by' => $user->id,
                ]);

            /*
        |--------------------------------------------------------------------------
        | Generar folio
        |--------------------------------------------------------------------------
        |
        | Ya tenemos:
        |
        | $order->id
        |
        | por lo tanto podemos generar un folio seguro:
        |
        | KVA-000123
        |
        */

            $order->update([
                'folio' => $this->generateFolio(
                    tenant: $tenant,
                    branch: $branch,
                    orderId: $order->id,
                ),
            ]);

            /*
        |--------------------------------------------------------------------------
        | Crear items
        |--------------------------------------------------------------------------
        */

            foreach (
                $lineSnapshots as $snapshot
            ) {
                $order
                    ->items()
                    ->create(
                        $snapshot
                    );
            }

            app(\App\Services\Cash\CashService::class)->attachSale($tenant, $branch, $user, $order);

            app(\App\Services\Inventory\InventoryLedgerService::class)->consumeOrder($tenant, $branch, $order);

            $this->fiscalTickets->issueForSale($tenant, $order);

            /*
        |--------------------------------------------------------------------------
        | Respuesta
        |--------------------------------------------------------------------------
        */

            return $order
                ->refresh()
                ->load([
                    'branch',
                    'items.product',
                ]);
        });
    }

    private function generateFolio(
        Tenant $tenant,
        Branch $branch,
        int $orderId,
    ): string {
        $prefix = $this
            ->ticketSettings
            ->prefix($tenant, $branch);

        return $prefix.'-'.str_pad(
            (string) $orderId,
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    private function money(string|int $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
