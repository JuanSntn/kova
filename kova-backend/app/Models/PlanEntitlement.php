<?php

namespace App\Models;

use App\Models\Concerns\ImmutableCatalog;
use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    use ImmutableCatalog;

    protected $table = 'plan_entitlements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'is_unlimited' => 'boolean'];
    }

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }
}
