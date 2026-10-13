<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Membership extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Membership $membership) => $membership->public_id ??= (string) Str::uuid());
    }

    protected $fillable = [
        'tenant_id',
        'public_id',
        'user_id',
        'login_name',
        'login_name_normalized',
        'status',
        'joined_at',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_memberships')->withTimestamps();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Memberships are intentionally not globally scoped: authentication needs
     * to locate a user's memberships before a tenant has been selected. Once
     * a tenant request is being handled, route binding must nevertheless be
     * tenant-scoped so a public UUID from another business is never resolved.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $tenant = app(CurrentTenant::class);

        $query = $query->where($field ?? $this->getRouteKeyName(), $value);

        if ($tenant->isResolved()) {
            $query = $query
                ->where('tenant_id', $tenant->id())
                ->where('status', '!=', MembershipStatus::Archived->value);
        }

        return $query;
    }
}
