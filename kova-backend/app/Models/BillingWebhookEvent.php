<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingWebhookEvent extends Model {

protected $table = 'billing_webhook_events';
protected $guarded = ['id'];
protected function casts(): array { return ['payload'=>'array','processed_at'=>'datetime']; }


}
