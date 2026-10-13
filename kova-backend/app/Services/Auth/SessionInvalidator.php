<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionInvalidator
{
    public function invalidate(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
