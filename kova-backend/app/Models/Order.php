<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'folio',
        'order_type',
        'status',
        'subtotal',
        'tax_base_total',
        'tax_total',
        'discount_total',
        'service_charge_total',
        'tip_total',
        'total',
        'payment_method',
        'paid_at',
        'amount_received',
        'change_amount',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_base_total' => 'decimal:2',
            'tax_total' => 'decimal:2',

            'discount_total' => 'decimal:2',
            'service_charge_total' => 'decimal:2',
            'tip_total' => 'decimal:2',

            'total' => 'decimal:2',

            'amount_received' => 'decimal:2',
            'change_amount' => 'decimal:2',

            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->public_id ??= (string) Str::uuid();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
