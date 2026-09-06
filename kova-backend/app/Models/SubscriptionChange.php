<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionChange extends Model {

use \App\Models\Concerns\BelongsToTenant;
protected $table = 'subscription_changes';
protected $guarded = ['id'];
protected function casts(): array { return ['effective_at'=>'datetime','completed_at'=>'datetime','retained_branch_ids'=>'array','quantity'=>'integer']; }
protected static function booted(): void { static::creating(fn ($m) => $m->public_id ??= (string) \Illuminate\Support\Str::uuid()); }

}
