<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlanVersion extends Model {
use \App\Models\Concerns\ImmutableCatalog;
protected $table = 'plan_versions';
protected $guarded = ['id'];
protected function casts(): array { return ['published_at'=>'datetime','retired_at'=>'datetime']; }

public function plan() { return $this->belongsTo(Plan::class); }
public function prices() { return $this->hasMany(PlanPrice::class); }
public function entitlements() { return $this->hasMany(PlanEntitlement::class); }
public function availableAddons() { return $this->belongsToMany(AddonVersion::class, 'plan_version_addons')->withPivot('automatic'); }
}
