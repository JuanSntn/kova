<?php

namespace App\Services\Subscriptions;

use App\Models\Feature;
use App\Models\Invoice;
use App\Models\SubscriptionOveragePolicy;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Models\UsagePeriod;
use App\Models\UsageReservation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called only by a trusted fiscal issuer after successful issuance. Never exposed as a client usage API. */
final class UsageService
{
    public function __construct(private EntitlementService $entitlements, private ConsumptionPeriod $periods, private ContractService $contracts, private CatalogService $catalog) {}

    public function recordIssuedStamp(Tenant $tenant, string $fiscalUuid, CarbonImmutable $issuedAt): UsageEvent
    {
        if (! Str::isUuid($fiscalUuid)) {
            throw new DomainException('Se requiere el UUID fiscal confirmado.');
        }
        $source = 'stamp:'.strtolower($fiscalUuid);

        return DB::transaction(function () use ($tenant, $source, $issuedAt) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = UsageEvent::where('source_id', $source)->first();
            if ($existing) {
                return $existing;
            }
            if ($issuedAt->gt(CarbonImmutable::now()->addMinute())) {
                throw new DomainException('La fecha de emisión está en el futuro.');
            }
            $s = $tenant->planSubscriptions()->where('starts_at', '<=', $issuedAt)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $issuedAt))->whereNotNull('plan_version_id')->latest('starts_at')->firstOrFail();
            $benefits = $this->entitlements->resolve($s, $issuedAt);
            if (! ($benefits['allows_invoicing'] ?? false)) {
                throw new DomainException('El contrato no incluye facturación.');
            }
            $feature = Feature::where('code', 'included_stamps')->firstOrFail();
            $period = $this->periods->for($s, $issuedAt);
            $row = UsagePeriod::firstOrCreate(['plan_subscription_id' => $s->id, 'feature_id' => $feature->id, 'starts_at' => $period['starts_at']], ['ends_at' => $period['ends_at'], 'included' => $benefits['included_stamps']]);
            $billable = $row->included === null ? 0 : (int) ($row->consumed >= $row->included);
            $policy = null;
            if ($billable) {
                $policy = SubscriptionOveragePolicy::query()->where('plan_subscription_id', $s->id)->where('feature_id', $feature->id)->where('enabled', true)->whereNull('effective_until')->latest('id')->first();
                if (! $policy) {
                    throw new DomainException('Terminaste los timbres incluidos y los excedentes están desactivados.');
                }
            }
            $event = UsageEvent::create([
                'usage_period_id' => $row->id, 'source_id' => $source, 'quantity' => 1, 'billable_quantity' => $billable,
                'sequence_number' => $row->consumed + 1, 'usage_type' => $billable ? 'overage' : 'included', 'is_overage' => (bool) $billable,
                'addon_price_id' => $policy?->addon_price_id, 'unit_amount' => $policy?->unit_amount, 'tax_rate_basis_points' => $policy?->tax_rate_basis_points,
                'estimated_unit_total' => $policy?->estimated_unit_total, 'currency' => $policy?->currency,
                'metering_status' => $billable ? 'pending' : 'not_billable', 'occurred_at' => $issuedAt,
            ]);
            $row->increment('consumed');
            if ($billable) {
                $addon = $s->addons()->whereHas('version', fn ($q) => $q->where('mode', 'metered'))->firstOrFail();
                $mapping = $this->catalog->mapping($addon->price);
                $this->contracts->enqueue($tenant, 'meter', 'usage:'.$source, ['event_id' => $event->id, 'event_name' => $mapping->meter_event_name, 'quantity' => 1, 'timestamp' => $issuedAt->timestamp]);
            }

            return $event;
        });
    }

    public function confirmIssuedStamp(Invoice $invoice, UsageReservation $reservation, string $fiscalUuid, CarbonImmutable $issuedAt): UsageEvent
    {
        if (! Str::isUuid($fiscalUuid)) {
            throw new DomainException('Se requiere el UUID fiscal confirmado.');
        }
        if ($invoice->tenant_id !== $reservation->tenant_id || $invoice->id !== $reservation->invoice_id) {
            throw new DomainException('La reserva no corresponde a la factura.');
        }
        $source = 'stamp:'.strtolower($fiscalUuid);

        return DB::transaction(function () use ($invoice, $reservation, $source, $issuedAt, $fiscalUuid) {
            $locked = UsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $existing = UsageEvent::query()->where(fn ($query) => $query->where('usage_reservation_id', $locked->id)->orWhere('source_id', $source))->first();
            if ($existing) {
                return $existing;
            }
            if (! in_array($locked->status, ['reserved', 'reconciliation_required'], true)) {
                throw new DomainException('La reserva de timbre ya no está disponible.');
            }
            $period = UsagePeriod::query()->whereKey($locked->usage_period_id)->lockForUpdate()->firstOrFail();
            $isOverage = $locked->usage_type === 'overage';
            $event = UsageEvent::query()->create([
                'usage_period_id' => $period->id,
                'invoice_id' => $invoice->id,
                'usage_reservation_id' => $locked->id,
                'source_id' => $source,
                'quantity' => 1,
                'billable_quantity' => $isOverage ? 1 : 0,
                'sequence_number' => $locked->sequence_number,
                'usage_type' => $locked->usage_type,
                'is_overage' => $isOverage,
                'addon_price_id' => $locked->addon_price_id,
                'unit_amount' => $locked->unit_amount,
                'tax_rate_basis_points' => $locked->tax_rate_basis_points,
                'estimated_unit_total' => $locked->estimated_unit_total,
                'currency' => $locked->currency,
                'metering_status' => $isOverage ? 'pending' : 'not_billable',
                'occurred_at' => $issuedAt,
            ]);
            $period->increment('consumed');
            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            if ($isOverage) {
                $mapping = $this->catalog->mapping($locked->policy->price);
                $this->contracts->enqueue($invoice->tenant, 'meter', 'usage:'.$source, [
                    'event_id' => $event->id,
                    'event_name' => $mapping->meter_event_name,
                    'quantity' => 1,
                    'timestamp' => $issuedAt->timestamp,
                    'identifier' => hash('sha256', $invoice->tenant->public_id.':'.$invoice->public_id.':'.strtolower($fiscalUuid)),
                ]);
            }

            return $event;
        }, 3);
    }
}
