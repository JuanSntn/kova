<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionAddon extends Model {

use \App\Models\Concerns\BelongsToTenant;
protected $table = 'subscription_addons';
protected $guarded = ['id'];
protected function casts(): array { return ['quantity'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime']; }
protected static function booted(): void { static::creating(fn ($m) => $m->public_id ??= (string) \Illuminate\Support\Str::uuid()); }
public function version() { return $this->belongsTo(AddonVersion::class, 'addon_version_id'); }
public function price() { return $this->belongsTo(AddonPrice::class, 'addon_price_id'); }
}
