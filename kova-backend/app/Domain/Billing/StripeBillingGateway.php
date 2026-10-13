<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Models\BillingOutbox;
use App\Models\PlanPrice;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\BillingReconciler;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Subscriptions\EntitlementService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

final class StripeBillingGateway implements BillingGateway
{
    public function __construct(private StripeClient $stripe, private CatalogService $catalog, private ContractService $contracts, private EntitlementService $entitlements, private BillingProcessor $processor, private BillingReconciler $reconciler) {}

    public function createCheckout(
        Tenant $tenant,
        User $user,
        string $plan,
        string $operationId,
        string $interval = 'month',
        ?string $offer = null,
        array $retainedBranches = [],
        array $retainedMemberships = [],
    ): string {
        $price = $this->catalog->price(
            $plan,
            $interval,
            $offer
        );

        $this->catalog->assertPurchasable($price);

        $current = $this->entitlements->current($tenant);

        /*
     * Cambio entre contratos pagados.
     */
        if ($current && $current->price->amount > 0) {
            $change = $this->contracts->requestPlanChange(
                subscription: $current,
                target: $price,
                branches: $retainedBranches,
                memberships: $retainedMemberships,
                operationId: $operationId,
            );

            $kind = $change->change_mode === 'immediate'
                ? 'upgrade_plan'
                : 'schedule_plan';

            $operation = BillingOutbox::query()
                ->where('kind', $kind)
                ->where('payload->change_id', $change->id)
                ->firstOrFail();

            $result = $this->processor->run($operation);

            /*
         * Si Stripe dejó un invoice pendiente para el upgrade,
         * enviamos al usuario a pagarlo.
         */
            if (
                $change->change_mode === 'immediate'
                && ($result['status'] ?? null) === 'awaiting_payment'
                && filled($result['invoice_url'] ?? null)
            ) {
                return $result['invoice_url'];
            }

            $state = $change->change_mode === 'immediate'
                ? (($result['status'] ?? null) === 'confirmed'
                    ? 'applied'
                    : 'processing')
                : 'scheduled';

            return rtrim(config('app.frontend_url'), '/')
                .'/'.$tenant->slug
                .'/billing?change='.$state;
        }

        /*
     * Free no necesita Checkout.
     */
        if ($price->amount === 0) {
            throw new DomainException(
                'El plan gratuito no requiere checkout.'
            );
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pending = $this->reconciler->pendingContract($tenant);

            if ($pending) {
                $url = $this->resolvePendingCheckout(
                    $tenant,
                    $pending,
                    $price,
                );

                if ($url !== null) {
                    return $url;
                }

                continue;
            }

            try {
                $decision = DB::transaction(function () use (
                    $tenant,
                    $price,
                    $user,
                    $operationId
                ): array {
                    Tenant::query()
                        ->whereKey($tenant->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $existing = BillingOutbox::query()
                        ->where(
                            'operation_key',
                            $tenant->id.':checkout:'.$operationId
                        )
                        ->first();

                    if ($existing) {
                        $contract = PlanSubscription::query()
                            ->findOrFail($existing->payload['contract_id']);

                        if ($contract->plan_price_id !== $price->id) {
                            throw new DomainException(
                                'La clave ya corresponde a otra oferta.'
                            );
                        }

                        if ($contract->ended_at) {
                            throw new DomainException(
                                'La clave corresponde a un intento de pago terminado.'
                            );
                        }

                        return [
                            'action' => 'process',
                            'operation_id' => $existing->id,
                        ];
                    }

                    $pending = PlanSubscription::query()
                        ->where('pending_tenant_id', $tenant->id)
                        ->first();

                    if ($pending) {
                        return [
                            'action' => 'pending',
                            'contract_id' => $pending->id,
                        ];
                    }

                    $subscription = $this->contracts->create(
                        $tenant,
                        $price
                    );
                    $operation = $this->contracts->enqueue(
                        $tenant,
                        'checkout',
                        'checkout:'.$operationId,
                        [
                            'contract_id' => $subscription->id,
                            'email' => $user->email,
                        ]
                    );

                    return [
                        'action' => 'process',
                        'operation_id' => $operation->id,
                    ];
                });
            } catch (QueryException $exception) {
                if ($this->reconciler->pendingContract($tenant)) {
                    continue;
                }

                throw $exception;
            }

            if ($decision['action'] === 'pending') {
                $url = $this->resolvePendingCheckout(
                    $tenant,
                    PlanSubscription::query()->findOrFail(
                        $decision['contract_id']
                    ),
                    $price,
                );

                if ($url !== null) {
                    return $url;
                }

                continue;
            }

            $result = $this->processor->run(
                BillingOutbox::query()->findOrFail(
                    $decision['operation_id']
                )
            );
            $url = $result['url'] ?? null;

            if (! is_string($url) || $url === '') {
                throw new DomainException(
                    'No pudimos preparar la sesión de pago.'
                );
            }

            return $url;
        }

        throw new DomainException(
            'No pudimos estabilizar el intento de pago. Actualiza el estado e intenta nuevamente.'
        );
    }

    private function resolvePendingCheckout(
        Tenant $tenant,
        PlanSubscription $pending,
        PlanPrice $price,
    ): ?string {
        $operation = $this->reconciler->checkoutOperation($pending);

        if (! $operation) {
            $this->reconciler->cancelPendingCheckout($tenant);

            return null;
        }

        if (! isset($operation->result['session_id'])) {
            if ($pending->plan_price_id !== $price->id) {
                $this->reconciler->cancelPendingCheckout($tenant);

                return null;
            }

            $result = $this->processor->run($operation);
            $url = $result['url'] ?? null;

            if (! is_string($url) || $url === '') {
                throw new DomainException(
                    'El intento de pago requiere reconciliación.'
                );
            }

            return $url;
        }

        $session = $this->reconciler->checkout($operation);
        $pending->refresh();
        $operation->refresh();

        if ($pending->ended_at || $session->status === 'expired') {
            return null;
        }

        if (
            $session->status === 'open'
            && $session->payment_status === 'unpaid'
        ) {
            if ($pending->plan_price_id !== $price->id) {
                $this->reconciler->cancelPendingCheckout($tenant);

                return null;
            }

            $url = $operation->result['url'] ?? null;

            if (! is_string($url) || $url === '') {
                throw new DomainException(
                    'No pudimos recuperar la sesión de pago pendiente.'
                );
            }

            return $url;
        }

        $this->reconciler->run($tenant);

        if ($this->entitlements->current($tenant)?->price->amount > 0) {
            return rtrim(config('app.frontend_url'), '/')
                .'/'.$tenant->slug
                .'/billing?checkout=success';
        }

        throw new DomainException(
            'El pago se está confirmando. Actualiza el estado antes de crear otro intento.'
        );
    }

    public function createPortal(Tenant $tenant): string
    {
        if (! $tenant->stripe_id) {
            throw new DomainException('El negocio todavía no tiene una cuenta de cobro.');
        }
        $configuration = config('billing.portal_configuration');
        if (! $configuration) {
            throw new DomainException('Falta configurar el portal de pagos.');
        }
        $portal = $this->stripe->billingPortal->configurations->retrieve($configuration);
        if (data_get($portal, 'features.subscription_update.enabled') || data_get($portal, 'features.subscription_cancel.enabled')) {
            throw new DomainException('El portal no puede modificar contratos fuera del sistema comercial.');
        }

        return $this->stripe->billingPortal->sessions->create(['customer' => $tenant->stripe_id, 'configuration' => $configuration, 'return_url' => rtrim(config('app.frontend_url'), '/').'/'.$tenant->slug.'/billing'])->url;
    }

    public function cancelSubscription(Tenant $tenant, array $retainedBranches = [], array $retainedMemberships = []): void
    {
        $this->setCancellation($tenant, true, $retainedBranches, $retainedMemberships);
    }

    public function reactivateSubscription(Tenant $tenant): void
    {
        $this->setCancellation($tenant, false);
    }

    private function setCancellation(Tenant $tenant, bool $cancel, array $retainedBranches = [], array $retainedMemberships = []): void
    {
        $kind = $cancel ? 'cancel' : 'reactivate';
        $opId = DB::transaction(function () use (
            $tenant,
            $cancel,
            $retainedBranches,
            $retainedMemberships,
            $kind,
        ): ?int {
            Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $s = $this->entitlements->current($tenant);

            if (! $s || ! $s->price->amount) {
                throw new DomainException('No hay una suscripción pagada vigente.');
            }

            if ($s->cancel_at_period_end === $cancel) {
                return null;
            }

            $this->contracts->assertNoPendingChange(
                $s,
                $cancel ? 'cancel' : 'reactivate',
            );

            if ($cancel) {
                $this->contracts->validateBranches(
                    $tenant,
                    $this->entitlements->forVersion(
                        $this->catalog->price('free')->version
                    )['included_branches'],
                    $retainedBranches
                );
                $this->contracts->validateMemberships(
                    $tenant,
                    $this->entitlements->forVersion($this->catalog->price('free')->version)['max_users'] ?? 0,
                    $retainedMemberships,
                );
            }

            $activeKinds = ['pending', 'processing', 'reconciliation_required'];
            $opposite = BillingOutbox::query()
                ->whereIn('kind', ['cancel', 'reactivate'])
                ->where('kind', '!=', $kind)
                ->where('payload->contract_id', $s->id)
                ->whereIn('status', $activeKinds)
                ->lockForUpdate()
                ->exists();

            if ($opposite) {
                throw new DomainException(
                    'Hay una operación contractual contraria en proceso. Actualiza su estado antes de continuar.'
                );
            }

            $payload = [
                'contract_id' => $s->id,
                'retained_branch_ids' => $retainedBranches,
                'retained_membership_ids' => $retainedMemberships,
            ];
            $operation = BillingOutbox::query()
                ->where('kind', $kind)
                ->where('payload->contract_id', $s->id)
                ->whereIn('status', $activeKinds)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($operation) {
                if (
                    $operation->payload !== $payload
                    && ($operation->attempts > 0 || $operation->status !== 'pending')
                ) {
                    throw new DomainException(
                        'La operación ya fue enviada a Stripe. Reconcilia su estado antes de cambiar la selección.'
                    );
                }

                if ($operation->payload !== $payload) {
                    $operation->update(['payload' => $payload]);
                }

                return $operation->id;
            }

            return $this->contracts->enqueue(
                $tenant,
                $kind,
                $kind.':'.$s->public_id.':'.Str::uuid(),
                $payload,
            )->id;
        });

        if ($opId === null) {
            return;
        }

        $this->processor->run(
            BillingOutbox::query()->findOrFail($opId)
        );
    }

    public function listInvoices(
        Tenant $tenant,
        int $limit = 5,
        ?string $startingAfter = null,
    ): array {
        $customerId = trim(
            (string) $tenant->stripe_id
        );

        /*
     * Si el tenant todavía no tiene customer en Stripe,
     * simplemente regresamos un historial vacío.
     */
        if ($customerId === '') {
            return [
                'data' => [],

                'pagination' => [
                    'limit' => $limit,
                    'has_more' => false,
                    'next_cursor' => null,
                ],
            ];
        }

        /*
     * Protección defensiva aunque el controlador
     * ya valide el límite.
     */
        $limit = max(
            1,
            min($limit, 20)
        );

        /*
     * Si recibimos cursor, comprobamos que esa factura
     * realmente pertenezca al customer del tenant actual.
     */
        if ($startingAfter !== null) {
            $startingAfter = trim($startingAfter);

            if ($startingAfter === '') {
                $startingAfter = null;
            } else {
                $this->assertInvoiceBelongsToCustomer(
                    invoiceId: $startingAfter,
                    customerId: $customerId,
                    tenantId: (string) $tenant->getKey(),
                );
            }
        }

        $parameters = [
            /*
         * Esta es la protección principal contra IDOR:
         * nunca recibimos customer_id desde el frontend.
         */
            'customer' => $customerId,

            'limit' => $limit,
        ];

        if ($startingAfter !== null) {
            $parameters['starting_after'] =
                $startingAfter;
        }

        try {
            $stripeInvoices = $this->stripe
                ->invoices
                ->all($parameters);
        } catch (
            InvalidRequestException $exception
        ) {
            Log::warning(
                'Cursor inválido al consultar facturas de Stripe.',
                [
                    'tenant_id' => $tenant->getKey(),

                    'stripe_customer_id' => $customerId,

                    'cursor' => $startingAfter,

                    'limit' => $limit,

                    'message' => $exception->getMessage(),

                    'stripe_code' => $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        } catch (
            ApiErrorException $exception
        ) {
            Log::error(
                'No se pudieron consultar las facturas de Stripe.',
                [
                    'tenant_id' => $tenant->getKey(),

                    'stripe_customer_id' => $customerId,

                    'cursor' => $startingAfter,

                    'limit' => $limit,

                    'message' => $exception->getMessage(),

                    'stripe_code' => $exception->getStripeCode(),
                ],
            );

            throw new DomainException(
                'No pudimos consultar las facturas. Intenta de nuevo.'
            );
        }

        $mappedInvoices = array_map(
            fn (mixed $invoice): array => $this->mapInvoice($invoice),
            $stripeInvoices->data,
        );

        /*
     * Stripe regresa primero las facturas más recientes.
     * El cursor siguiente es el ID de la última factura
     * de esta página.
     */
        $lastInvoice = ! empty($stripeInvoices->data)
            ? $stripeInvoices->data[array_key_last(
                $stripeInvoices->data
            )]
            : null;

        $hasMore = (bool) (
            $stripeInvoices->has_more
            ?? false
        );

        return [
            'data' => $mappedInvoices,

            'pagination' => [
                'limit' => $limit,

                'has_more' => $hasMore,

                'next_cursor' => (
                    $hasMore
                    && $lastInvoice !== null
                )
                    ? (string) $lastInvoice->id
                    : null,
            ],
        ];
    }

    private function assertInvoiceBelongsToCustomer(
        string $invoiceId,
        string $customerId,
        string $tenantId,
    ): void {
        try {
            $invoice = $this->stripe
                ->invoices
                ->retrieve(
                    $invoiceId,
                    []
                );
        } catch (
            ApiErrorException $exception
        ) {
            Log::warning(
                'Se intentó usar un cursor de factura inválido.',
                [
                    'tenant_id' => $tenantId,

                    'stripe_customer_id' => $customerId,

                    'cursor' => $invoiceId,

                    'message' => $exception->getMessage(),

                    'stripe_code' => $exception->getStripeCode(),
                ],
            );

            /*
         * No revelamos si la factura existe.
         */
            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        }

        /*
     * customer puede venir como ID o como objeto
     * si estuviera expandido.
     */
        $invoiceCustomerId =
            data_get($invoice, 'customer.id')
            ?? data_get($invoice, 'customer');

        if (
            ! is_string($invoiceCustomerId)
            || $invoiceCustomerId === ''
            || ! hash_equals(
                $customerId,
                $invoiceCustomerId
            )
        ) {
            Log::warning(
                'Se intentó usar una factura de otro customer como cursor.',
                [
                    'tenant_id' => $tenantId,

                    'stripe_customer_id' => $customerId,

                    'cursor' => $invoiceId,
                ],
            );

            throw new DomainException(
                'El cursor de facturación no es válido.'
            );
        }
    }

    private function mapInvoice(
        mixed $invoice,
    ): array {
        $totalInCents = (int) (
            $invoice->total
            ?? 0
        );

        $amountDueInCents = (int) (
            $invoice->amount_due
            ?? 0
        );

        $amountPaidInCents = (int) (
            $invoice->amount_paid
            ?? 0
        );

        $amountRemainingInCents = (int) (
            $invoice->amount_remaining
            ?? 0
        );

        $status = (string) (
            $invoice->status
            ?? 'unknown'
        );

        return [
            'id' => (string) $invoice->id,

            'number' => $invoice->number
                ?? null,

            'date' => isset($invoice->created)
                ? CarbonImmutable::createFromTimestamp(
                    (int) $invoice->created
                )->toIso8601String()
                : null,

            'plan_name' => $this->resolveInvoicePlanName(
                $invoice
            ),

            'total' => $this->fromStripeAmount(
                $totalInCents
            ),

            'amount_due' => $this->fromStripeAmount(
                $amountDueInCents
            ),

            'amount_paid' => $this->fromStripeAmount(
                $amountPaidInCents
            ),

            'amount_remaining' => $this->fromStripeAmount(
                $amountRemainingInCents
            ),

            'currency' => strtoupper(
                (string) (
                    $invoice->currency
                    ?? 'mxn'
                )
            ),

            /*
         * Estado original de Stripe.
         */
            'status' => $status,

            /*
         * Estado amigable para el frontend.
         */
            'status_label' => $this->invoiceStatusLabel(
                status: $status,
                totalInCents: $totalInCents,
                amountDueInCents: $amountDueInCents,
                amountPaidInCents: $amountPaidInCents,
            ),

            'billing_reason' => $invoice->billing_reason
                ?? null,

            'url' => $invoice->hosted_invoice_url
                ?? null,

            'pdf' => $invoice->invoice_pdf
                ?? null,
        ];
    }

    private function fromStripeAmount(
        int $amountInCents,
    ): float {
        return round(
            $amountInCents / 100,
            2
        );
    }

    private function invoiceStatusLabel(
        string $status,
        int $totalInCents,
        int $amountDueInCents,
        int $amountPaidInCents,
    ): string {
        /*
     * Ejemplo:
     * una factura borrador con importe negativo
     * normalmente representa un crédito o ajuste.
     */
        if (
            $status === 'draft'
            && $totalInCents < 0
        ) {
            return 'Crédito pendiente';
        }

        /*
     * La factura fue completada sin cobrar dinero.
     * Puede haber sido cubierta con saldo o crédito.
     */
        if (
            $status === 'paid'
            && $amountDueInCents === 0
            && $amountPaidInCents === 0
        ) {
            return 'Sin cargo';
        }

        return match ($status) {
            'draft' => 'Borrador',
            'open' => 'Pendiente de pago',
            'paid' => 'Pagada',
            'void' => 'Anulada',
            'uncollectible' => 'Incobrable',
            default => 'Desconocido',
        };
    }

    private function resolveInvoicePlanName(
        mixed $invoice,
    ): string {
        /*
     * Primero intentamos obtenerlo desde metadata.
     */
        $invoicePlanName = data_get(
            $invoice,
            'metadata.plan_name'
        );

        if (
            is_string($invoicePlanName)
            && $invoicePlanName !== ''
        ) {
            return $invoicePlanName;
        }

        /*
     * Después revisamos metadata de la primera línea.
     */
        $linePlanName = data_get(
            $invoice,
            'lines.data.0.metadata.plan_name'
        );

        if (
            is_string($linePlanName)
            && $linePlanName !== ''
        ) {
            return $linePlanName;
        }

        /*
     * Intentamos obtener el nickname del Price.
     */
        $priceNickname = data_get(
            $invoice,
            'lines.data.0.price.nickname'
        );

        if (
            is_string($priceNickname)
            && $priceNickname !== ''
        ) {
            return $priceNickname;
        }

        /*
     * Finalmente usamos la descripción de la línea.
     */
        $description = data_get(
            $invoice,
            'lines.data.0.description'
        );

        if (
            is_string($description)
            && $description !== ''
        ) {
            return $description;
        }

        return '—';
    }
}
