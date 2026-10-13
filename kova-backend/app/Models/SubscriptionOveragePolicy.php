<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionOveragePolicy extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'consented_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }

    public function subscription()
    {
        return $this->belongsTo(PlanSubscription::class, 'plan_subscription_id');
    }

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }

    public function addon()
    {
        return $this->belongsTo(SubscriptionAddon::class, 'subscription_addon_id');
    }

    public function price()
    {
        return $this->belongsTo(AddonPrice::class, 'addon_price_id');
    }
}
