<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BillingSubscriptionLink extends Model
{
    use BelongsToTenant;

    protected $table = 'billing_subscription_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_starts_at' => 'datetime', 'period_ends_at' => 'datetime', 'past_due_since' => 'datetime', 'last_event_at' => 'datetime'];
    }
}
