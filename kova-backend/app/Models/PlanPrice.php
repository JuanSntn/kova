<?php

namespace App\Models;

use App\Models\Concerns\ImmutableCatalog;
use Illuminate\Database\Eloquent\Model;

class PlanPrice extends Model
{
    use ImmutableCatalog;

    protected $table = 'plan_prices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function version()
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }
}
