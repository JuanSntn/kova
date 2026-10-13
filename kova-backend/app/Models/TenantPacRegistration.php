<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantPacRegistration extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function profile()
    {
        return $this->belongsTo(TenantFiscalProfile::class, 'tenant_fiscal_profile_id');
    }
}
