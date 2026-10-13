<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BillingOutbox extends Model
{
    use BelongsToTenant;

    protected $table = 'billing_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }
}
