<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingOutbox extends Model {

use \App\Models\Concerns\BelongsToTenant;
protected $table = 'billing_outbox';
protected $guarded = ['id'];
protected function casts(): array { return ['payload'=>'array','result'=>'array','available_at'=>'datetime','locked_at'=>'datetime']; }


}
