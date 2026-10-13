<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'public_id',
        'product_id',
        'product_name_snapshot',
        'modifiers_snapshot',
        'inventory_snapshot',
        'preparation_station_snapshot',
        'preparation_status',
        'preparation_started_at',
        'preparation_ready_at',
        'unit_price_snapshot',
        'quantity',
        'tax_name_snapshot',
        'tax_code_snapshot',
        'tax_factor_type_snapshot',
        'tax_rate_snapshot',
        'tax_base',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'modifiers_snapshot' => 'array',
            'inventory_snapshot' => 'array',
            'preparation_started_at' => 'datetime',
            'preparation_ready_at' => 'datetime',
            'unit_price_snapshot' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:4',
            'tax_base' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            $item->public_id ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
