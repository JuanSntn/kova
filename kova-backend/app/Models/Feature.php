<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected static function booted(): void
    {
        static::updating(function ($m) {
            if ($m->isDirty(['code', 'type', 'unit', 'reset_policy', 'additive']) && (PlanEntitlement::where('feature_id', $m->id)->exists() || AddonEntitlement::where('feature_id', $m->id)->exists())) {
                throw new \DomainException('La definición de una capacidad utilizada es inmutable.');
            }
        });
    }

    protected $table = 'features';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['additive' => 'boolean'];
    }
}
