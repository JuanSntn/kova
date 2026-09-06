<?php

namespace App\Services\Pos;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\PlanLimitService;
use App\Services\Taxes\ProductTaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Tickets\TicketSettingsService;

final class OrderService
{
    public function __construct(
        private readonly ProductTaxService $taxes,
        private readonly PlanLimitService $limits,
        private readonly TicketSettingsService $ticketSettings,
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

            $branch = $this->resolveSingleActiveBranch(
                $tenant
            );

            /*
        |--------------------------------------------------------------------------
        | Impuesto predeterminado
        |--------------------------------------------------------------------------
        */

            $defaultTax = $this->taxes->defaultTax(
                $tenant
            );

            /*
        |--------------------------------------------------------------------------
        | Items recibidos
        |--------------------------------------------------------------------------
        */

            $items = collect(
                $data['items']
            );

            $productIds = $items
                ->pluck('product_id')
                ->unique()
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Productos reales del tenant
        |--------------------------------------------------------------------------
        |
        | Nunca confiamos en precios enviados por frontend.
        |
        */

            $products = $tenant
                ->products()
                ->with('tax')
                ->whereIn(
                    'public_id',
                    $productIds
                )
                ->where(
                    'is_active',
                    true
                )
                ->get()
                ->keyBy(
                    'public_id'
                );

            /*
        |--------------------------------------------------------------------------
        | Validar productos
        |--------------------------------------------------------------------------
        */

            if (
                $products->count() !==
                $productIds->count()
            ) {
                throw ValidationException::withMessages([
                    'items' =>
                    'Uno o más productos no están disponibles.',
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Construir snapshots
        |--------------------------------------------------------------------------
        */

            $lineSnapshots = [];

            foreach ($items as $item) {
                /** @var Product $product */
                $product = $products->get(
                    $item['product_id']
                );

                $quantity = (int) $item['quantity'];

                /*
             * Precio oficial desde BD.
             */
                $unitPrice = $this->money(
                    (float) $product->price
                );

                /*
             * Total bruto de la línea.
             *
             * El precio ya incluye impuestos.
             */
                $lineTotal = $this->money(
                    $unitPrice *
                        $quantity
                );

                /*
             * Impuesto efectivo:
             *
             * producto.tax_id
             * o
             * impuesto predeterminado.
             */
                $tax = $this->taxes
                    ->resolveEffectiveTax(
                        $product,
                        $defaultTax,
                    );

                /*
             * Desglose del impuesto incluido.
             */
                $taxBreakdown = $this->taxes
                    ->calculateIncludedTax(
                        $lineTotal,
                        $tax,
                    );

                /*
             * Snapshot histórico.
             */
                $lineSnapshots[] = [
                    'product_id' =>
                    $product->id,

                    'product_name_snapshot' =>
                    $product->name,

                    'unit_price_snapshot' =>
                    $unitPrice,

                    'quantity' =>
                    $quantity,

                    'tax_name_snapshot' =>
                    $tax->name,

                    'tax_code_snapshot' =>
                    $tax->code,

                    'tax_factor_type_snapshot' =>
                    $tax->factor_type,

                    'tax_rate_snapshot' =>
                    $tax->rate,

                    'tax_base' =>
                    $taxBreakdown['tax_base'],

                    'tax_amount' =>
                    $taxBreakdown['tax_amount'],

                    'line_total' =>
                    $taxBreakdown['line_total'],
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | Totales
        |--------------------------------------------------------------------------
        */

            $subtotal = $this->money(
                array_sum(
                    array_column(
                        $lineSnapshots,
                        'line_total'
                    )
                )
            );

            $taxBaseTotal = $this->money(
                array_sum(
                    array_column(
                        $lineSnapshots,
                        'tax_base'
                    )
                )
            );

            $taxTotal = $this->money(
                array_sum(
                    array_column(
                        $lineSnapshots,
                        'tax_amount'
                    )
                )
            );

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
                    (float) $data['amount_received']
                );

                /*
             * Segunda validación a nivel de dominio.
             *
             * Aunque el frontend ya valida esto,
             * Laravel es la autoridad.
             */
                if (
                    $amountReceived <
                    $total
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
                    $amountReceived -
                        $total
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
                    'branch_id' =>
                    $branch->id,

                    /*
                 * Se genera después usando
                 * $order->id.
                 */
                    'folio' =>
                    null,

                    'order_type' =>
                    'counter',

                    'status' =>
                    'paid',

                    'subtotal' =>
                    $subtotal,

                    'tax_base_total' =>
                    $taxBaseTotal,

                    'tax_total' =>
                    $taxTotal,

                    'discount_total' =>
                    0,

                    'service_charge_total' =>
                    0,

                    'tip_total' =>
                    0,

                    'total' =>
                    $total,

                    'payment_method' =>
                    $data['payment_method'],

                    'amount_received' =>
                    $amountReceived,

                    'change_amount' =>
                    $changeAmount,

                    'paid_at' =>
                    now(),

                    'created_by' =>
                    $user->id,
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
                'folio' =>
                $this->generateFolio(
                    tenant: $tenant,

                    orderId: $order->id,
                ),
            ]);

            /*
        |--------------------------------------------------------------------------
        | Crear items
        |--------------------------------------------------------------------------
        */

            foreach (
                $lineSnapshots
                as $snapshot
            ) {
                $order
                    ->items()
                    ->create(
                        $snapshot
                    );
            }

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

    private function resolveSingleActiveBranch(Tenant $tenant): Branch
    {
        $branches = $tenant
            ->branches()
            ->where('is_active', true)
            ->get();

        if ($branches->count() !== 1) {
            throw new DomainConflictException(
                'El POS Free requiere exactamente una sucursal activa.',
                'POS_BRANCH_NOT_RESOLVED',
            );
        }

        return $branches->sole();
    }

    private function generateFolio(
        Tenant $tenant,
        int $orderId,
    ): string {
        $prefix = $this
            ->ticketSettings
            ->prefix($tenant);

        return $prefix . '-' . str_pad(
            (string) $orderId,
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }
}
