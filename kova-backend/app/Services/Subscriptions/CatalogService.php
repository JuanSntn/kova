<?php
namespace App\Services\Subscriptions;

use App\Models\{Plan,PlanVersion,PlanPrice,Feature,Addon,AddonVersion,AddonPrice,BillingPriceMapping};
use DomainException;
use Illuminate\Support\Facades\DB;

final class CatalogService
{
    public function createVersion(Plan|Addon $identity, array $data): PlanVersion|AddonVersion
    {
        return DB::transaction(function () use ($identity, $data) {
            $identity->newQuery()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $version = $identity->versions()->create([
                'version'=>($identity->versions()->max('version') ?? 0)+1,
                'name'=>$data['name'] ?? $identity->name, 'mode'=>$data['mode'] ?? 'recurring',
            ]);
            foreach ($data['entitlements'] ?? [] as $code=>$value) {
                $feature = Feature::where('code',$code)->firstOrFail();
                if ($value !== null && (!is_int($value) && !is_bool($value) || $value < 0)) throw new DomainException('El beneficio debe ser un entero no negativo, booleano o null para ilimitado.');
                if ($feature->type === 'boolean' && !is_bool($value)) throw new DomainException('La capacidad '.$code.' requiere un booleano.');
                $version->entitlements()->create(['feature_id'=>$feature->id,'value'=>$value, 'is_unlimited'=>$value === null]);
            }
            foreach ($data['prices'] ?? [] as $interval=>$amount) {
                if (!in_array($interval,['month','year']) || !is_int($amount) || $amount < 0) throw new DomainException('Precio o periodicidad inválidos.');
                if ($identity instanceof Addon && $interval !== 'month') throw new DomainException('Los extras se cobran mensualmente.');
                $version->prices()->create(['interval'=>$interval,'amount'=>$amount,'currency'=>'mxn']);
            }
            if ($version instanceof PlanVersion) {
                foreach ($data['addons'] ?? [] as $addon) {
                    $av = AddonVersion::where('public_id',$addon['version'])->where('status','published')->firstOrFail();
                    $version->availableAddons()->attach($av->id, ['automatic'=>$addon['automatic'] ?? false]);
                }
            }
            return $version;
        });
    }

    public function publish(PlanVersion|AddonVersion $version): void
    {
        DB::transaction(function () use ($version) {
            $version = $version->newQuery()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($version->status !== 'draft') throw new DomainException('Solo se publican borradores.');
            $this->validate($version);
            $version->update(['status'=>'published','published_at'=>now()]);
        });
    }

    public function validate(PlanVersion|AddonVersion $version): void
    {
        if (!$version->prices()->exists()) throw new DomainException('Faltan precios.');
        if (!in_array($version->mode,['recurring','metered'])) throw new DomainException('Modalidad inválida.');
        if ($version instanceof PlanVersion) {
            $required = Feature::pluck('id')->all();
            if (array_diff($required,$version->entitlements()->pluck('feature_id')->all())) throw new DomainException('Define todas las capacidades, incluso las no incluidas.');
        }
    }

    public function retire(PlanVersion|AddonVersion $version): void
    {
        if ($version->status !== 'published') throw new DomainException('Solo se retiran versiones publicadas.');
        $version->update(['status'=>'retired','retired_at'=>now()]);
    }

    public function price(string $code, string $interval = 'month', ?string $offer = null): PlanPrice
    {
        $query = PlanPrice::with('version.plan')->where('interval',$interval)
            ->whereHas('version', fn ($q)=>$q->where('status','published')->whereHas('plan', fn ($p)=>$p->where('code',$code)->where('is_active',true)));
        if ($offer) $query->where('public_id',$offer);
        $price = $query->orderByDesc('id')->first();
        if (!$price) throw new DomainException('Oferta no disponible.');
        return $price;
    }

    public function mapping(PlanPrice|AddonPrice $price): BillingPriceMapping
    {
        $mapping = BillingPriceMapping::where($price instanceof PlanPrice ? 'plan_price_id':'addon_price_id',$price->id)
            ->where('livemode',config('billing.livemode',false))->first();
        if (!$mapping) throw new DomainException('La oferta todavía no tiene el cobro configurado.');
        return $mapping;
    }

    public function assertPurchasable(PlanPrice $price): void
    {
        if ($price->amount === 0) return;
        $this->mapping($price);
        foreach ($price->version->availableAddons as $addon) {
            foreach ($addon->prices as $extraPrice) $this->mapping($extraPrice);
        }
    }

    public function offers(): array
    {
        return Plan::where('is_active',true)->get()->map(function ($plan) {
            $version = $plan->versions()->where('status','published')->latest('version')->first();
            if (!$version) return null;
            $benefits = app(EntitlementService::class)->forVersion($version);
            return array_merge(['code'=>$plan->code,'name'=>$version->name], $benefits, [
                'monthly_price'=> $this->decimal($version->prices->firstWhere('interval','month')?->amount ?? 0),
                'version'=>$version->public_id,
                'prices'=>$version->prices->map(function ($p) {
                    try { $this->assertPurchasable($p); $available=true; } catch (DomainException) { $available=false; }
                    return ['offer_id'=>$p->public_id,'interval'=>$p->interval,'currency'=>$p->currency,'amount'=>$p->amount,'available'=>$available];
                })->all(),
                'addons'=>$version->availableAddons->map(fn ($a)=>['version'=>$a->public_id,'code'=>$a->addon->code,'name'=>$a->name,'mode'=>$a->mode,'automatic'=>(bool)$a->pivot->automatic,'prices'=>$a->prices->map(fn ($p)=>['offer_id'=>$p->public_id,'amount'=>$p->amount,'currency'=>$p->currency,'interval'=>$p->interval])->all()])->all(),
            ]);
        })->filter()->values()->all();
    }

    public function decimal(int $amount): string { return intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT); }
}
