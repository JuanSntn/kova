<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FiscalOutbox extends Model
{
    use BelongsToTenant;

    protected $table = 'fiscal_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
