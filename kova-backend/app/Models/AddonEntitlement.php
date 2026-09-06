<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AddonEntitlement extends Model {
use \App\Models\Concerns\ImmutableCatalog;
protected $table = 'addon_entitlements';
protected $guarded = ['id'];
protected function casts(): array { return ['value'=>'integer','is_unlimited'=>'boolean']; }

public function feature() { return $this->belongsTo(Feature::class); }
}
