<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Settings\TicketSettingsController;

Route::middleware([
    'auth:sanctum',
    'verified',
    'tenant',
])
    ->prefix('settings/ticket')
    ->group(function () {
        Route::get(
            '/',
            [
                TicketSettingsController::class,
                'show',
            ]
        );

        Route::put(
            '/',
            [
                TicketSettingsController::class,
                'update',
            ]
        );
    });
