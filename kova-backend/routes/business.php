<?php

use App\Http\Controllers\Settings\BusinessProfileController;
use App\Http\Controllers\Settings\TicketSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    'account-access',
    'tenant',
])
    ->prefix('settings')
    ->group(function () {
        /*
        |--------------------------------------------------------------------------
        | Business
        |--------------------------------------------------------------------------
        */
        Route::get('/business', [BusinessProfileController::class, 'show',]);

        /*
         * POST intencional:
         *
         * Permite FormData + archivos
         * de forma simple para el logo.
         */
        Route::post('/business', [BusinessProfileController::class, 'update',]);

       
    });
