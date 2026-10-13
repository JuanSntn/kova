<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BranchProductSetting extends Model
{
    protected $fillable = ['branch_id', 'product_id', 'is_available', 'price_override'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'price_override' => 'decimal:2'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
