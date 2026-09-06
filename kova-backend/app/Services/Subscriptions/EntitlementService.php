<?php
namespace App\Services\Subscriptions;
use App\Models\{PlanVersion,PlanSubscription,Tenant};
use DomainException;

final class EntitlementService
{
    public function current(Tenant $tenant): ?PlanSubscription
    {
        return $tenant->planSubscriptions()->where('current_tenant_id',$tenant->id)->with(['version.entitlements.feature','price','addons.version.entitlements.feature'])->first();
    }
    public function forVersion(PlanVersion $version): array
    {
        $values=[];
        foreach ($version->entitlements()->with('feature')->get() as $e) {
            $values[$e->feature->code] = $e->is_unlimited ? null : ($e->feature->type === 'boolean' ? (bool)$e->value : (int)$e->value);
        }
        return $values;
    }
    public function resolve(PlanSubscription $subscription): array
    {
        $values=$this->forVersion($subscription->version);
        foreach ($subscription->addons()->where('status','active')->where('starts_at','<=',now())->where(fn ($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now()))->with('version.entitlements.feature')->get() as $addon) {
            foreach ($addon->version->entitlements as $e) {
                $code=$e->feature->code;
                if ($e->feature->type === 'boolean') $values[$code]=($values[$code] ?? false) || (bool)$e->value;
                elseif ($e->feature->additive && array_key_exists($code,$values) && $values[$code] !== null) $values[$code]=$e->is_unlimited ? null : $values[$code]+$e->value*$addon->quantity;
            }
        }
        return $values;
    }
    public function allows(Tenant $tenant, string $feature): bool
    {
        $subscription=$this->current($tenant);
        return $subscription && ($this->resolve($subscription)[$feature] ?? false) === true;
    }
    public function assertWritable(Tenant $tenant): void
    {
        $s=$this->current($tenant);
        if (!$s) throw new DomainException('El negocio no tiene un contrato vigente.');
        if ($s->status->value === 'suspended' || ($s->grace_ends_at && now()->greaterThanOrEqualTo($s->grace_ends_at))) throw new DomainException('La suscripción está en modo de consulta. Actualiza tu pago.');
    }
}
