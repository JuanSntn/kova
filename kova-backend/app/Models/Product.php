<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'tax_id',
        'name',
        'description',
        'price',
        'image_path',
        'sort_order',
        'is_active',
        'preparation_station',
        'billing_enabled',
        'sat_product_service_code',
        'sat_unit_code',
        'sat_unit_name',
        'sat_tax_object_code',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'billing_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (Product $product): void {
                if (! $product->public_id) {
                    $product->public_id =
                        (string) Str::uuid();
                }
            }
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            Category::class
        );
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(
            Tax::class
        );
    }

    public function branchSettings(): HasMany
    {
        return $this->hasMany(BranchProductSetting::class);
    }
}
