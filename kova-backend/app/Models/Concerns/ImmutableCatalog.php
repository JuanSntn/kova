<?php
namespace App\Models\Concerns;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ImmutableCatalog
{
    protected static function bootImmutableCatalog(): void
    {
        static::creating(function (Model $model) {
            if (in_array($model->getTable(), ['plan_versions','addon_versions','plan_prices','addon_prices'])) $model->public_id ??= (string) Str::uuid();
        });
        static::saving(function (Model $model) {
            $table = $model->getTable();
            $versionTable = str_starts_with($table, 'addon_') ? 'addon_versions' : 'plan_versions';
            if (str_ends_with($table, '_versions')) {
                if ($model->exists && $model->getRawOriginal('published_at') !== null && $model->isDirty(array_diff(array_keys($model->getDirty()), ['status','retired_at','updated_at']))) {
                    throw new DomainException('Las versiones publicadas son inmutables. Crea una nueva versión.');
                }
                if ($model->exists && $model->getRawOriginal('published_at') !== null && $model->isDirty('status') && $model->status !== 'retired') throw new DomainException('Solo puedes retirar una versión publicada.');
            } else {
                $key = str_starts_with($table, 'addon_') ? 'addon_version_id' : 'plan_version_id';
                $ids = array_filter([$model->getAttribute($key), $model->getRawOriginal($key)]);
                if (DB::table($versionTable)->whereIn('id', $ids)->whereNotNull('published_at')->exists()) throw new DomainException('No puedes modificar condiciones publicadas.');
            }
        });
        static::deleting(function (Model $model) {
            $table = $model->getTable();
            $key = str_starts_with($table, 'addon_') ? 'addon_version_id' : 'plan_version_id';
            $locked = str_ends_with($table, '_versions') ? $model->published_at !== null : DB::table(str_starts_with($table, 'addon_') ? 'addon_versions' : 'plan_versions')->where('id', $model->$key)->whereNotNull('published_at')->exists();
            if ($locked) throw new DomainException('No puedes eliminar condiciones publicadas.');
        });
    }
}
