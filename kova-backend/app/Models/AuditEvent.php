<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AuditEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = [
        'id',
        'tenant_id',
        'public_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            $event->public_id ??= (string) Str::uuid();
        });

        static::updating(static function (): never {
            throw new LogicException('Audit events are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'encrypted:array',
            'new_values' => 'encrypted:array',
            'reason' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
