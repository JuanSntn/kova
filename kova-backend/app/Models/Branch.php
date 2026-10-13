<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Branch extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'code',
        'name',
        'timezone',
        'postal_code',
        'state',
        'municipality',
        'locality',
        'city',
        'neighborhood',
        'street',
        'exterior_number',
        'interior_number',
        'country_code',
        'latitude',
        'longitude',
        'address',
        'phone',
        'is_active',
        'billing_enabled',
    ];

    protected static function booted(): void
    {
        static::creating(
            function (Branch $branch): void {
                $branch->public_id ??= (string) Str::uuid();
                $branch->staff_login_code ??= self::newStaffLoginCode($branch->code);
            }
        );
    }

    private static function newStaffLoginCode(?string $branchCode): string
    {
        $prefix = Str::upper(preg_replace('/[^A-Z0-9]/i', '', (string) $branchCode) ?: 'KVA');
        $prefix = str_pad(substr($prefix, 0, 3), 3, 'X');
        do {
            $code = $prefix.'-'.Str::upper(Str::random(5));
        } while (self::query()->where('staff_login_code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return [
            'address' => 'array',

            'latitude' => 'decimal:7',

            'longitude' => 'decimal:7',

            'is_active' => 'boolean',

            'billing_enabled' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'branch_memberships')->withTimestamps();
    }

    public function productSettings(): HasMany
    {
        return $this->hasMany(BranchProductSetting::class);
    }

    public function fiscalSetting(): HasOne
    {
        return $this->hasOne(BranchFiscalSetting::class);
    }
}
