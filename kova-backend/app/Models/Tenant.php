<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

class Tenant extends Model
{
    use Billable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'currency',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Apply the fixed, exclusive Mexican VAT rate to every Stripe subscription item.
     *
     * @return list<string>
     */
    public function taxRates(): array
    {
        $taxRate = config('billing.tax_rate');

        return is_string($taxRate) && $taxRate !== '' ? [$taxRate] : [];
    }

    /**
     * Never expose internal database identifiers in Stripe metadata.
     *
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        return [
            'tenant_public_id' => $this->public_id,
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(
            Category::class
        );
    }

    public function products(): HasMany
    {
        return $this->hasMany(
            Product::class
        );
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(
            Tax::class
        );
    }

    public function taxSettings(): HasOne
    {
        return $this->hasOne(
            TenantTaxSetting::class
        );
    }

    public function orders(): HasMany
    {
        return $this->hasMany(
            Order::class
        );
    }

    public function ticketSettings(): HasOne
    {
        return $this->hasOne(
            TenantTicketSetting::class
        );
    }
}
