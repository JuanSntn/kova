<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SatCatalogVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime', 'started_at' => 'datetime', 'imported_at' => 'datetime',
            'validated_at' => 'datetime', 'activated_at' => 'datetime', 'counts' => 'array',
        ];
    }
}
