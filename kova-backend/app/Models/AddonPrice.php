<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AddonPrice extends Model {
use \App\Models\Concerns\ImmutableCatalog;
protected $table = 'addon_prices';
protected $guarded = ['id'];
protected function casts(): array { return ['amount'=>'integer']; }

public function version() { return $this->belongsTo(AddonVersion::class, 'addon_version_id'); }
}
