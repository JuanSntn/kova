<?php

namespace App\Services\Subscriptions;

use App\Models\Feature;
use App\Models\PlanSubscription;
use App\Models\PlanVersion;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use DomainException;

final class EntitlementService
{
    public function __construct(
        private BillingAccessPolicy $billingAccess,
    ) {}

    public function current(Tenant $tenant): ?PlanSubscription
    {
        return $tenant->planSubscriptions()->where('current_tenant_id', $tenant->id)->with(['version.entitlements.feature', 'price', 'addons.version.entitlements.feature'])->first();
    }

    public function forVersion(PlanVersion $version): array
    {
        $values = Feature::get()->mapWithKeys(fn ($f) => [$f->code => $f->type === 'boolean' ? false : 0])->all();
        foreach ($version->entitlements()->with('feature')->get() as $e) {
            $values[$e->feature->code] = $e->is_unlimited ? null : ($e->feature->type === 'boolean' ? (bool) $e->value : (int) $e->value);
        }

        return $values;
    }

    public function resolve(PlanSubscription $subscription, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $values = $this->forVersion($subscription->version);
        foreach ($subscription->addons()->whereIn('status', ['active', 'ended'])->where('starts_at', '<=', $at)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))->with('version.entitlements.feature')->get() as $addon) {
            foreach ($addon->version->entitlements as $e) {
                $code = $e->feature->code;
                if ($e->feature->type === 'boolean') {
                    $values[$code] = ($values[$code] ?? false) || (bool) $e->value;
                } elseif ($e->feature->additive && array_key_exists($code, $values) && $values[$code] !== null) {
                    $values[$code] = $e->is_unlimited ? null : $values[$code] + $e->value * $addon->quantity;
                }
            }
        }

        return $values;
    }

    public function allows(Tenant $tenant, string $feature): bool
    {
        $subscription = $this->current($tenant);

        return $subscription && ($this->resolve($subscription)[$feature] ?? false) === true;
    }

    public function allowsCash(Tenant $tenant): bool
    {
        $subscription = $this->current($tenant);
        if (! $subscription) { return false; }
        $values = $this->resolve($subscription);
        return array_key_exists('max_users', $values)
            && ($values['max_users'] === null || $values['max_users'] > 1);
    }

    public function assertWritable(Tenant $tenant): void
    {
        $s = $this->current($tenant);
        if (! $s) {
            throw new DomainException('El negocio no tiene un contrato vigente.');
        }
        if (! $this->billingAccess->isWritable($s)) {
            throw new DomainException('La suscripción está en modo de consulta. Actualiza tu pago.');
        }
    }
}
