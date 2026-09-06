<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Branch extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'code',
        'name',
        'timezone',
        'address',
        'phone',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            $branch->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
