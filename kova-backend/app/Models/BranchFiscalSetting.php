<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BranchFiscalSetting extends Model
{
    protected $fillable = ['branch_id', 'fiscal_series_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(FiscalSeries::class, 'fiscal_series_id');
    }
}
