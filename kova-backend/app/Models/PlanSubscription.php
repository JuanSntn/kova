<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanSubscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'plan_id', 'public_id', 'plan_version_id', 'plan_price_id', 'current_tenant_id', 'usage_anchor_at', 'grace_ends_at', 'cancel_at_period_end',
        'provider',
        'stripe_subscription_id',
        'stripe_price_id',
        'status',
        'monthly_price_snapshot',
        'entitlements_snapshot',
        'starts_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancelled_at',
        'ended_at',
        'last_stripe_event_created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'usage_anchor_at'=>'datetime', 'grace_ends_at'=>'datetime', 'cancel_at_period_end'=>'boolean',
            'monthly_price_snapshot' => 'decimal:2',
            'entitlements_snapshot' => 'array',
            'starts_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_stripe_event_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void { static::creating(fn ($m) => $m->public_id ??= (string) \Illuminate\Support\Str::uuid()); }
    public function version() { return $this->belongsTo(PlanVersion::class, 'plan_version_id'); }
    public function price() { return $this->belongsTo(PlanPrice::class, 'plan_price_id'); }
    public function addons() { return $this->hasMany(SubscriptionAddon::class); }
    public function billingLinks() { return $this->hasMany(BillingSubscriptionLink::class); }
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
