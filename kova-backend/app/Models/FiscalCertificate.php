<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FiscalCertificate extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $hidden = ['certificate_path', 'private_key_path', 'encrypted_password'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'activated_at' => 'datetime', 'last_validated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }

    public function profile()
    {
        return $this->belongsTo(TenantFiscalProfile::class, 'tenant_fiscal_profile_id');
    }
}
