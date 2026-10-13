<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'sort_order',
        'is_active',
        'billing_enabled',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'billing_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (Category $category): void {
                if (! $category->public_id) {
                    $category->public_id =
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

    public function products(): HasMany
    {
        return $this->hasMany(
            Product::class
        );
    }
}
