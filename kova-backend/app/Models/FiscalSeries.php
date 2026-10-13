<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FiscalSeries extends Model
{
    use BelongsToTenant;

    protected $table = 'fiscal_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_folio' => 'integer', 'is_active' => 'boolean'];
    }
}
