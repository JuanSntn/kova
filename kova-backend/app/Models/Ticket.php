<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'token_hash',
        'encrypted_token',
        'access_code_hash',
        'encrypted_access_code',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_eligible' =>
            'boolean',

            'expires_at' =>
            'datetime',

            'used_at' =>
            'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (
                Ticket $ticket
            ): void {
                $ticket->public_id ??=
                    (string) Str::uuid();
            }
        );
    }

    public function order()
    {
        return $this->belongsTo(
            Order::class
        );
    }

    public function invoice()
    {
        return $this
            ->hasOne(
                Invoice::class
            )
            ->latestOfMany();
    }

    public function invoices()
    {
        return $this->hasMany(
            Invoice::class
        );
    }
}
