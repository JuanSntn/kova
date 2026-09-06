<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AddonVersion extends Model {
use \App\Models\Concerns\ImmutableCatalog;
protected $table = 'addon_versions';
protected $guarded = ['id'];
protected function casts(): array { return ['published_at'=>'datetime','retired_at'=>'datetime']; }

public function addon() { return $this->belongsTo(Addon::class); }
public function prices() { return $this->hasMany(AddonPrice::class); }
public function entitlements() { return $this->hasMany(AddonEntitlement::class); }
}
