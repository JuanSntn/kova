<?php

namespace App\Services\Pos;

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/** A write must precede all reads when serializing SQLite transactions. */
final class TenantWriteTransaction
{
    public function run(Tenant $tenant, callable $callback, string $code = 'POS_CHECKOUT_RETRY'): mixed
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $timeout = $sqlite ? (int) DB::selectOne('PRAGMA busy_timeout')->timeout : null;
        if ($sqlite) {
            DB::statement('PRAGMA busy_timeout = 250');
        }
        try {
            for ($attempt = 0; $attempt < 4; $attempt++) {
                try {
                    return DB::transaction(function () use ($tenant, $callback) {
                        DB::table('tenants')->where('id', $tenant->id)->update(['id' => $tenant->id]);

                        return $callback();
                    });
                } catch (QueryException $e) {
                    if (! $sqlite || ! in_array((int) ($e->errorInfo[1] ?? 0), [5, 6], true)) {
                        throw $e;
                    }
                    if ($attempt < 3) {
                        usleep(25000 * ($attempt + 1));
                    }
                }
            }
            throw new HttpResponseException(response()->json([
                'message' => 'El resultado no está confirmado. Reintenta con la misma clave.', 'code' => $code,
            ], 503, ['Retry-After' => '1']));
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA busy_timeout = '.$timeout);
            }
        }
    }
}
