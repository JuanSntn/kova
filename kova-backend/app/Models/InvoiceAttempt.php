<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvoiceAttempt extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
