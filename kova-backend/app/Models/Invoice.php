<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key_hash', 'unsigned_xml_path', 'stamped_xml_path', 'pdf_path', 'cancellation_receipt_path'];

    protected function casts(): array
    {
        return ['issuer_snapshot' => 'array', 'receiver_snapshot' => 'array', 'branch_snapshot' => 'array', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id');
    }

    public function replacements()
    {
        return $this->hasMany(self::class, 'replaces_invoice_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function attempts()
    {
        return $this->hasMany(InvoiceAttempt::class);
    }

    public function reservation()
    {
        return $this->hasOne(UsageReservation::class);
    }
}
