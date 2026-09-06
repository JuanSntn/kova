<?php

namespace App\Models;

use App\Enums\PlanCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'monthly_price',
        'max_users',
        'max_products',
        'max_categories',
        'monthly_sales_limit',
        'included_branches',
        'included_stamps',
        'additional_stamp_price',
        'additional_branch_price',
        'allows_counter_orders',
        'allows_table_orders',
        'allows_cash_shifts',
        'allows_inventory',
        'allows_invoicing',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            
            'monthly_price' => 'decimal:2',
            'monthly_sales_limit' => 'decimal:2',
            'additional_stamp_price' => 'decimal:2',
            'additional_branch_price' => 'decimal:2',
            'allows_counter_orders' => 'boolean',
            'allows_table_orders' => 'boolean',
            'allows_cash_shifts' => 'boolean',
            'allows_inventory' => 'boolean',
            'allows_invoicing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function versions() { return $this->hasMany(PlanVersion::class); }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    /**
     * Snapshot-able limits used by subscription enforcement.
     *
     * @return array<string, int|string|bool|null>
     */
    public function entitlements(): array
    {
        return [
            'max_users' => $this->max_users,
            'max_products' => $this->max_products,
            'max_categories' => $this->max_categories,
            'monthly_sales_limit' => $this->monthly_sales_limit,
            'included_branches' => $this->included_branches,
            'included_stamps' => $this->included_stamps,
            'additional_stamp_price' => $this->additional_stamp_price,
            'additional_branch_price' => $this->additional_branch_price,
            'allows_counter_orders' => $this->allows_counter_orders,
            'allows_table_orders' => $this->allows_table_orders,
            'allows_cash_shifts' => $this->allows_cash_shifts,
            'allows_inventory' => $this->allows_inventory,
            'allows_invoicing' => $this->allows_invoicing,
        ];
    }
}
