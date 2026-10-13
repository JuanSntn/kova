<?php
namespace App\Services\Subscriptions;
use App\Models\AddonPrice;
use App\Models\BillingPriceMapping;
use App\Models\PlanPrice;
use DomainException;
use Illuminate\Support\Facades\DB;
use Stripe\Billing\Meter;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;
use Stripe\StripeClient;
final class StripeCatalogSyncService
{
    public function __construct(private readonly StripeClient $stripe,) {}
    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | Sincronizamos únicamente versiones publicadas.
    |
    | Free no crea Stripe Product/Price:
    | es un contrato 100% local.
    |
    */
    public function sync(): array
    {
        $this->assertStripeConfiguration();
        $results = [];
        foreach (PlanPrice::query()->with('version.plan')->whereHas('version', fn($query) => $query->where('status', 'published'))->orderBy('id')->get()
            as $price) {
            $results[] = $this->syncPlanPrice($price);
        }
        foreach (AddonPrice::query()->with('version.addon')->whereHas('version', fn($query) => $query->where('status', 'published'))->orderBy('id')->get()
            as $price) {
            $results[] = $this->syncAddonPrice($price);
        }
        return $results;
    }
    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    |
    | No toca Stripe.
    |
    */
    public function preview(): array
    {
        $results = [];
        foreach (PlanPrice::query()->with('version.plan')->whereHas('version', fn($query) => $query->where('status', 'published'))->orderBy('id')->get()
            as $price) {
            $results[] = $this->resultRow(kind: 'plan', code: $price->version->plan->code, version: (int) $price->version->version, interval: $price->interval, amount: (int) $price->amount, currency: $price->currency, stripePriceId: null, lookupKey: $this->lookupKey('plan', $price->version->plan->code, (int) $price->version->version, $price->interval, $price->currency,), action: (int) $price->amount
                        === 0
                        ? 'local_only'
                        : 'pending_sync',);
        }
        foreach (AddonPrice::query()->with('version.addon')->whereHas('version', fn($query) => $query->where('status', 'published'))->orderBy('id')->get()
            as $price) {
            $results[] = $this->resultRow(kind: 'addon', code: $price->version->addon->code, version: (int) $price->version->version, interval: $price->interval, amount: (int) $price->amount, currency: $price->currency, stripePriceId: null, lookupKey: $this->lookupKey('addon', $price->version->addon->code, (int) $price->version->version, $price->interval, $price->currency,), action: 'pending_sync',);
        }
        return $results;
    }
    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */
    private function syncPlanPrice(PlanPrice $price): array {
        $code = $price->version->plan->code;
        $version = (int) $price->version->version;
        /*
         * Free no utiliza Stripe.
         */
        if ((int) $price->amount
            === 0) {
            return $this->resultRow(kind: 'plan', code: $code, version: $version, interval: $price->interval, amount: (int) $price->amount, currency: $price->currency, stripePriceId: null, lookupKey: $this->lookupKey('plan', $code, $version, $price->interval, $price->currency,), action: 'local_only',);
        }
        return $this->syncRemotePrice(kind: 'plan', code: $code, name: $price->version->name, version: $version, localPriceId: $price->id, localPricePublicId: $price->public_id, mappingColumn: 'plan_price_id', interval: $price->interval, amount: (int) $price->amount, currency: $price->currency,
            /*
             * Los planes base siempre son licensed.
             */
            mode: 'recurring',);
    }
    /*
    |--------------------------------------------------------------------------
    | Addons
    |--------------------------------------------------------------------------
    */
    private function syncAddonPrice(AddonPrice $price): array {
        return $this->syncRemotePrice(kind: 'addon', code: $price->version->addon->code, name: $price->version->name, version: (int) $price->version->version, localPriceId: $price->id, localPricePublicId: $price->public_id, mappingColumn: 'addon_price_id', interval: $price->interval, amount: (int) $price->amount, currency: $price->currency, mode: $price->version->mode,);
    }
    /*
    |--------------------------------------------------------------------------
    | Remote price synchronization
    |--------------------------------------------------------------------------
    */
    private function syncRemotePrice(string $kind, string $code, string $name, int $version, int $localPriceId, string $localPricePublicId, string $mappingColumn, string $interval, int $amount, string $currency, string $mode,): array {
        $currency = strtolower($currency);
        $lookupKey = $this->lookupKey($kind, $code, $version, $interval, $currency,);
        $usageType = $mode === 'metered'
            ? 'metered'
            : 'licensed';
        /*
         * Primero respetamos cualquier mapping
         * que ya existe.
         *
         * Esto hace compatible el refactor con
         * los Prices que Kova ya venía utilizando.
         */
        $mapping = BillingPriceMapping::query()->where($mappingColumn, $localPriceId)->where('livemode', $this->livemode())->first();
        /*
         * Mapping existente:
         *
         * nunca creamos otro Price.
         */
        if ($mapping) {
            $remote = $this->retrieveMappedPrice($mapping);
            $this->assertPriceMatches(remote: $remote, expectedAmount: $amount, expectedCurrency: $currency, expectedInterval: $interval, expectedUsageType: $usageType,);
            $action = 'verified';
            /*
             * Los Prices históricos de Kova pueden
             * no tener lookup_key.
             *
             * Si el Price ya está correctamente
             * mapeado, lo adoptamos en lugar de
             * reemplazarlo.
             */
            if (blank($remote->lookup_key)) {
                $existingByLookup = $this->findPriceByLookupKey($lookupKey);
                if ($existingByLookup
                    && $existingByLookup->id
                    !== $remote->id) {
                    throw new DomainException('El lookup_key '
                            . $lookupKey
                            . ' ya pertenece a otro Price de Stripe.');
                }
                $remote = $this->stripe->prices->update($remote->id, [ 'lookup_key' => $lookupKey, ], $this->requestOptions('adopt-price:'
                                    . $localPricePublicId));
                $action = 'adopted';
            } elseif ($remote->lookup_key
                !== $lookupKey) {
                throw new DomainException('El mapping local apunta a un Price con lookup_key distinto. '
                        . 'Esperado: '
                        . $lookupKey
                        . '. Actual: '
                        . $remote->lookup_key
                        . '.');
            }
            /*
             * Si es metered, verificamos y recuperamos
             * Billing Meter + event_name.
             */
            [ $meterId, $eventName, ] = $this->meterDataFromRemotePrice(remote: $remote, expectedUsageType: $usageType, mapping: $mapping,);
            /*
             * Permite adoptar mappings anteriores que
             * tengan Price correcto pero todavía no
             * hayan guardado metadata del Meter.
             */
            if ($mapping->stripe_meter_id
                !== $meterId
                || $mapping->meter_event_name
                !== $eventName) {
                $mapping->update([ 'stripe_meter_id' => $meterId, 'meter_event_name' => $eventName, ]);
            }
            return $this->resultRow(kind: $kind, code: $code, version: $version, interval: $interval, amount: $amount, currency: $currency, stripePriceId: $remote->id, lookupKey: $lookupKey, action: $action,);
        }
        /*
         * No hay mapping local.
         *
         * Antes de crear algo revisamos lookup_key.
         *
         * Esto recupera correctamente el caso:
         *
         * Stripe create ✅
         * proceso se cae
         * mapping local ❌
         */
        $remote = $this->findPriceByLookupKey($lookupKey);
        if ($remote) {
            $this->assertPriceMatches(remote: $remote, expectedAmount: $amount, expectedCurrency: $currency, expectedInterval: $interval, expectedUsageType: $usageType,);
            [ $meterId, $eventName, ] = $this->meterDataFromRemotePrice(remote: $remote, expectedUsageType: $usageType, mapping: null,);
            $this->storeMapping(mappingColumn: $mappingColumn, localPriceId: $localPriceId, stripePriceId: $remote->id, meterId: $meterId, eventName: $eventName,);
            return $this->resultRow(kind: $kind, code: $code, version: $version, interval: $interval, amount: $amount, currency: $currency, stripePriceId: $remote->id, lookupKey: $lookupKey, action: 'linked',);
        }
        /*
         * Realmente no existe.
         *
         * Ahora sí creamos infraestructura nueva.
         */
        $product = $this->ensureProduct(kind: $kind, code: $code, name: $name,);
        $meter = null;
        $eventName = null;
        $recurring = [ 'interval' => $interval, 'usage_type' => $usageType, ];
        /*
         * extra_stamp:
         *
         * Billing Meter real de Stripe.
         */
        if ($usageType
            === 'metered') {
            $eventName = $this->meterEventName($code, $version,);
            $meter = $this->ensureMeter(displayName: $name
                        . ' v'
                        . $version, eventName: $eventName,);
            $recurring['meter'] = $meter->id;
        }
        /*
         * Stripe Price es inmutable en monto,
         * moneda y periodicidad.
         *
         * Una nueva PlanVersion creará otro
         * lookup_key y otro Price.
         */
        $remote = $this->stripe->prices->create([ 'product' => $product->id, 'currency' => $currency, 'unit_amount' => $amount, 'recurring' => $recurring, 'lookup_key' => $lookupKey,
                    /*
                         * Kova agrega impuestos
                         * por separado.
                         */
                    'tax_behavior' => 'exclusive', 'nickname' => 'Kova '
                        . $name
                        . ' v'
                        . $version
                        . ' '
                        . $interval, 'metadata' => [ 'app' => 'kova', 'catalog_type' => $kind, 'catalog_code' => $code, 'catalog_version' => (string) $version, 'offer_public_id' => $localPricePublicId, ], ], $this->requestOptions('create-price:'
                            . $localPricePublicId));
        /*
         * Verificamos incluso lo que acabamos
         * de crear antes de persistir mapping.
         */
        $this->assertPriceMatches(remote: $remote, expectedAmount: $amount, expectedCurrency: $currency, expectedInterval: $interval, expectedUsageType: $usageType,);
        $this->storeMapping(mappingColumn: $mappingColumn, localPriceId: $localPriceId, stripePriceId: $remote->id, meterId: $meter?->id, eventName: $eventName,);
        return $this->resultRow(kind: $kind, code: $code, version: $version, interval: $interval, amount: $amount, currency: $currency, stripePriceId: $remote->id, lookupKey: $lookupKey, action: 'created',);
    }
    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    |
    | El Product representa la identidad:
    |
    | business
    | restaurant
    | extra_stamp
    | extra_branch
    |
    | Las versiones cambian Price, no Product.
    |
    */
    private function ensureProduct(string $kind, string $code, string $name,): StripeProduct {
        $productId = $this->productId($kind, $code,);
        try {
            $product = $this->stripe->products->retrieve($productId);
        } catch (InvalidRequestException
            $exception) {
            if ($exception->getStripeCode()
                !== 'resource_missing'
                && $exception->getHttpStatus()
                !== 404) {
                throw $exception;
            }
            /*
             * Product ID determinístico.
             *
             * Esto permite volver a encontrarlo sin
             * guardar otra tabla de mapping.
             */
            $product = $this->stripe->products->create([ 'id' => $productId, 'name' => 'Kova - '
                            . $name, 'metadata' => [ 'app' => 'kova', 'catalog_type' => $kind, 'catalog_code' => $code, ], ], $this->requestOptions('create-product:'
                                . $kind
                                . ':'
                                . $code));
        }
        if ((bool) $product->livemode
            !== $this->livemode()) {
            throw new DomainException('El Product de Stripe pertenece a un modo distinto al configurado.');
        }
        /*
         * No adoptamos por accidente un Product
         * ajeno a Kova.
         */
        if (data_get($product, 'metadata.app')
            !== 'kova'
            || data_get($product, 'metadata.catalog_type')
            !== $kind
            || data_get($product, 'metadata.catalog_code')
            !== $code) {
            throw new DomainException('El Product '
                    . $productId
                    . ' ya existe en Stripe pero no pertenece al catálogo esperado de Kova.');
        }
        /*
         * Nombre del Product sí es mutable.
         *
         * Cambiar Negocio → Negocio Pro, por ejemplo,
         * no modifica el contrato económico.
         */
        if ($product->name
            !== 'Kova - '
            . $name) {
            $product = $this->stripe->products->update($product->id, [ 'name' => 'Kova - '
                            . $name, ], $this->requestOptions('rename-product:'
                                . $kind
                                . ':'
                                . $code));
        }
        return $product;
    }
    /*
    |--------------------------------------------------------------------------
    | Billing Meters
    |--------------------------------------------------------------------------
    */
    private function ensureMeter(string $displayName, string $eventName,): Meter {
        $meter = $this->findMeterByEventName($eventName);
        if ($meter) {
            $this->assertMeterMatches($meter, $eventName);
            return $meter;
        }
        $meter = $this->stripe->billing->meters->create([ 'display_name' => $displayName, 'event_name' => $eventName, 'default_aggregation' => [ 'formula' => 'sum', ],
                    /*
                         * BillingProcessor manda:
                         *
                         * stripe_customer_id
                         * value
                         */
                    'customer_mapping' => [ 'type' => 'by_id', 'event_payload_key' => 'stripe_customer_id', ], 'value_settings' => [ 'event_payload_key' => 'value', ], ], $this->requestOptions('create-meter:'
                            . $eventName));
        $this->assertMeterMatches($meter, $eventName);
        return $meter;
    }
    /*
    |--------------------------------------------------------------------------
    | Recover meter information
    |--------------------------------------------------------------------------
    */
    private function meterDataFromRemotePrice(StripePrice $remote, string $expectedUsageType, ?BillingPriceMapping $mapping,): array {
        if ($expectedUsageType
            !== 'metered') {
            return [ null, null, ];
        }
        $meterId = $this->objectId(data_get($remote, 'recurring.meter'));
        if (! $meterId) {
            throw new DomainException('El Price metered '
                    . $remote->id
                    . ' no tiene Billing Meter asociado.');
        }
        if ($mapping
            ?->stripe_meter_id
            && $mapping->stripe_meter_id
            !== $meterId) {
            throw new DomainException('El Billing Meter del mapping no coincide con el Price remoto.');
        }
        $meter = $this->stripe->billing->meters->retrieve($meterId);
        $this->assertMeterMatches($meter, $meter->event_name);
        if ($mapping
            ?->meter_event_name
            && $mapping->meter_event_name
            !== $meter->event_name) {
            throw new DomainException('El event_name del mapping no coincide con el Billing Meter remoto.');
        }
        return [ $meter->id, $meter->event_name, ];
    }
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    private function assertPriceMatches(StripePrice $remote, int $expectedAmount, string $expectedCurrency, string $expectedInterval, string $expectedUsageType,): void {
        if ((bool) $remote->livemode
            !== $this->livemode()) {
            throw new DomainException('El Price '
                    . $remote->id
                    . ' pertenece a un modo de Stripe distinto al configurado.');
        }
        if (! $remote->active) {
            throw new DomainException('El Price '
                    . $remote->id
                    . ' está inactivo en Stripe.');
        }
        if ((int) $remote->unit_amount
            !== $expectedAmount) {
            throw new DomainException('El monto de '
                    . $remote->id
                    . ' no coincide con el catálogo local.');
        }
        if (strtolower((string) $remote->currency)
            !== $expectedCurrency) {
            throw new DomainException('La moneda de '
                    . $remote->id
                    . ' no coincide con el catálogo local.');
        }
        if (data_get($remote, 'recurring.interval')
            !== $expectedInterval) {
            throw new DomainException('La periodicidad de '
                    . $remote->id
                    . ' no coincide con el catálogo local.');
        }
        if (data_get($remote, 'recurring.usage_type')
            !== $expectedUsageType) {
            throw new DomainException('El usage_type de '
                    . $remote->id
                    . ' no coincide con el catálogo local.');
        }
    }
    private function assertMeterMatches(Meter $meter, string $expectedEventName,): void {
        if ((bool) $meter->livemode
            !== $this->livemode()) {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' pertenece a otro modo de Stripe.');
        }
        if ($meter->status
            !== 'active') {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' no está activo.');
        }
        if ($meter->event_name
            !== $expectedEventName) {
            throw new DomainException('El event_name del Billing Meter '
                    . $meter->id
                    . ' no coincide.');
        }
        if (data_get($meter, 'default_aggregation.formula')
            !== 'sum') {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' no usa agregación sum.');
        }
        if (data_get($meter, 'customer_mapping.type')
            !== 'by_id') {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' no mapea clientes por ID.');
        }
        if (data_get($meter, 'customer_mapping.event_payload_key')
            !== 'stripe_customer_id') {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' no usa stripe_customer_id.');
        }
        if (data_get($meter, 'value_settings.event_payload_key')
            !== 'value') {
            throw new DomainException('El Billing Meter '
                    . $meter->id
                    . ' no usa value como campo de consumo.');
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Existing mapping
    |--------------------------------------------------------------------------
    */
    private function retrieveMappedPrice(BillingPriceMapping $mapping): StripePrice {
        try {
            return $this->stripe->prices->retrieve($mapping->stripe_price_id);
        } catch (InvalidRequestException
            $exception) {
            if ($exception->getStripeCode()
                === 'resource_missing'
                || $exception->getHttpStatus()
                === 404) {
                throw new DomainException('El mapping local apunta a un Price inexistente en Stripe: '
                        . $mapping->stripe_price_id
                        . '.');
            }
            throw $exception;
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Lookup key recovery
    |--------------------------------------------------------------------------
    */
    private function findPriceByLookupKey(string $lookupKey): ?StripePrice {
        $matches = [];
        /*
         * Stripe permite filtrar Prices por lookup_key.
         *
         * Revisamos active + inactive para detectar
         * configuraciones viejas en vez de crear duplicados.
         */
        foreach ([ true, false, ]
            as $active) {
            $collection = $this->stripe->prices->all([ 'lookup_keys' => [ $lookupKey, ], 'active' => $active, 'limit' => 10, ]);
            foreach ($collection->data
                as $price) {
                $matches[$price->id] = $price;
            }
        }
        if (count($matches)
            > 1) {
            throw new DomainException('Stripe devolvió más de un Price para lookup_key '
                    . $lookupKey
                    . '.');
        }
        return array_values($matches)[0]
            ?? null;
    }
    /*
    |--------------------------------------------------------------------------
    | Meter recovery
    |--------------------------------------------------------------------------
    */
    private function findMeterByEventName(string $eventName): ?Meter {
        $matches = [];
        foreach ([ 'active', 'inactive', ]
            as $status) {
            $collection = $this->stripe->billing->meters->all([ 'status' => $status, 'limit' => 100, ]);
            foreach ($collection->autoPagingIterator()
                as $meter) {
                if ($meter->event_name
                    === $eventName) {
                    $matches[$meter->id] = $meter;
                }
            }
        }
        if (count($matches)
            > 1) {
            throw new DomainException('Stripe tiene más de un Billing Meter con event_name '
                    . $eventName
                    . '.');
        }
        return array_values($matches)[0]
            ?? null;
    }
    /*
    |--------------------------------------------------------------------------
    | Mapping persistence
    |--------------------------------------------------------------------------
    |
    | Las llamadas a Stripe ya ocurrieron antes de entrar
    | en esta transacción.
    |
    | No hacemos network I/O dentro de DB::transaction().
    |
    */
    private function storeMapping(string $mappingColumn, int $localPriceId, string $stripePriceId, ?string $meterId, ?string $eventName,): BillingPriceMapping {
        return DB::transaction(function () use ($mappingColumn, $localPriceId, $stripePriceId, $meterId, $eventName,) {
                /*
                 * stripe_price_id es UNIQUE.
                 *
                 * No permitimos accidentalmente que un
                 * Price remoto represente dos ofertas Kova.
                 */
                $conflict = BillingPriceMapping::query()->where('stripe_price_id', $stripePriceId)->lockForUpdate()->first();
                if ($conflict) {
                    $belongsToExpectedLocalPrice = $mappingColumn
                        === 'plan_price_id'
                        ? ($conflict->plan_price_id
                            === $localPriceId
                            && $conflict->addon_price_id
                            === null)
                        : ($conflict->addon_price_id
                            === $localPriceId
                            && $conflict->plan_price_id
                            === null);
                    if (! $belongsToExpectedLocalPrice) {
                        throw new DomainException('El Price '
                                . $stripePriceId
                                . ' ya está mapeado a otra oferta local.');
                    }
                }
                $otherColumn = $mappingColumn
                    === 'plan_price_id'
                    ? 'addon_price_id'
                    : 'plan_price_id';
                return BillingPriceMapping::query()->updateOrCreate([ $mappingColumn => $localPriceId, 'livemode' => $this->livemode(), ], [ $otherColumn => null, 'stripe_price_id' => $stripePriceId, 'stripe_meter_id' => $meterId, 'meter_event_name' => $eventName, ]);
            });
    }
    /*
    |--------------------------------------------------------------------------
    | Stripe configuration
    |--------------------------------------------------------------------------
    */
    private function assertStripeConfiguration(): void
    {
        $secret = (string) config('cashier.secret');
        if (blank($secret)) {
            throw new DomainException('Falta configurar la clave secreta de Stripe.');
        }
        $isLiveSecret = str_starts_with($secret, 'sk_live_')
            || str_starts_with($secret, 'rk_live_');
        $isTestSecret = str_starts_with($secret, 'sk_test_')
            || str_starts_with($secret, 'rk_test_');
        if ($this->livemode()
            && ! $isLiveSecret) {
            throw new DomainException('BILLING_LIVEMODE=true requiere una clave Stripe live.');
        }
        if (! $this->livemode()
            && ! $isTestSecret) {
            throw new DomainException('BILLING_LIVEMODE=false requiere una clave Stripe test.');
        }
    }
    private function livemode(): bool
    {
        return (bool) config('billing.livemode', false);
    }
    /*
    |--------------------------------------------------------------------------
    | Deterministic identifiers
    |--------------------------------------------------------------------------
    */
    private function lookupKey(string $kind, string $code, int $version, string $interval, string $currency,): string {
        return implode('_', [ 'kova', $this->slug($kind), $this->slug($code), 'v'
                    . $version, $this->slug($interval), $this->slug($currency), ]);
    }
    private function productId(string $kind, string $code,): string {
        return 'prod_kova_'
            . $this->slug($kind)
            . '_'
            . $this->slug($code);
    }
    private function meterEventName(string $code, int $version,): string {
        return 'kova_meter_'
            . $this->slug($code)
            . '_v'
            . $version;
    }
    private function slug(string $value): string {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value)
            ?? $value;
        return trim($value, '_');
    }
    /*
    |--------------------------------------------------------------------------
    | Stripe idempotency
    |--------------------------------------------------------------------------
    */
    private function requestOptions(string $key): array {
        return [ 'idempotency_key' => 'kova_catalog_'
                . hash('sha256', $key), ];
    }
    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    private function objectId(mixed $value): ?string {
        if (is_string($value)) {
            return $value;
        }
        if (is_object($value)
            && isset($value->id)) {
            return (string) $value->id;
        }
        return null;
    }
    private function resultRow(string $kind, string $code, int $version, string $interval, int $amount, string $currency, ?string $stripePriceId, string $lookupKey, string $action,): array {
        return [ 'kind' => $kind, 'code' => $code, 'version' => $version, 'interval' => $interval, 'amount' => $amount, 'currency' => strtolower($currency), 'stripe_price_id' => $stripePriceId, 'lookup_key' => $lookupKey, 'action' => $action, ];
    }
}
