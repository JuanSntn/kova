<?php

namespace App\Models;

use App\Models\Concerns\ImmutableCatalog;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PlanVersionAddon extends Pivot
{
    use ImmutableCatalog;

    protected $table = 'plan_version_addons';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['automatic' => 'boolean'];
    }
}
