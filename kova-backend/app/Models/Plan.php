<?php

namespace App\Models;

use App\Services\Subscriptions\EntitlementService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'tier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(
            PlanVersion::class
        );
    }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(
            PlanSubscription::class
        );
    }

    public function entitlements(): array
    {
        $version = $this
            ->versions()
            ->where('status', 'published')
            ->latest('version')
            ->firstOrFail();

        return app(
            EntitlementService::class
        )->forVersion($version);
    }
}
