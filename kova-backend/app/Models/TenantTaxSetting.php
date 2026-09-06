<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantTaxSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'default_tax_id',
        'prices_include_tax',
    ];

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'default_tax_id');
    }
}
