<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBusinessProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'phone',
        'public_email',
        'logo_path',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }
}
