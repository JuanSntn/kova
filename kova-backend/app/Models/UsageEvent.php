<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'usage_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metered_at' => 'datetime', 'quantity' => 'integer', 'billable_quantity' => 'integer', 'sequence_number' => 'integer', 'is_overage' => 'boolean'];
    }
}
