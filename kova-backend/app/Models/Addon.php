<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Addon extends Model {

protected $table = 'addons';
protected $guarded = ['id'];
protected function casts(): array { return ['is_active'=>'boolean']; }

public function versions() { return $this->hasMany(AddonVersion::class); }
}
