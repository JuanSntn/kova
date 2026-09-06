<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UsagePeriod extends Model {

use \App\Models\Concerns\BelongsToTenant;
protected $table = 'usage_periods';
protected $guarded = ['id'];
protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','consumed'=>'integer','included'=>'integer']; }


}
