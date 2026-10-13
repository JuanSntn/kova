<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPriceMapping extends Model
{
    protected $table = 'billing_price_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['livemode' => 'boolean'];
    }
}
