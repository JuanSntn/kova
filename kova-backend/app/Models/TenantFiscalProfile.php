<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TenantFiscalProfile extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['validated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }

    public function certificates()
    {
        return $this->hasMany(FiscalCertificate::class);
    }

    public function registrations()
    {
        return $this->hasMany(TenantPacRegistration::class);
    }
}
