<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UsageReservation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'released_at' => 'datetime', 'sequence_number' => 'integer'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function period()
    {
        return $this->belongsTo(UsagePeriod::class, 'usage_period_id');
    }

    public function policy()
    {
        return $this->belongsTo(SubscriptionOveragePolicy::class, 'subscription_overage_policy_id');
    }
}
