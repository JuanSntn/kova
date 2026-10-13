<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class UsagePeriod extends Model
{
    use BelongsToTenant;

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }

    public function events() { return $this->hasMany(UsageEvent::class); }
    public function reservations() { return $this->hasMany(UsageReservation::class); }

    protected $table = 'usage_periods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'consumed' => 'integer', 'included' => 'integer'];
    }
}
