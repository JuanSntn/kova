<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UsageEvent extends Model {

use \App\Models\Concerns\BelongsToTenant;
protected $table = 'usage_events';
protected $guarded = ['id'];
protected function casts(): array { return ['occurred_at'=>'datetime','quantity'=>'integer','billable_quantity'=>'integer']; }


}
