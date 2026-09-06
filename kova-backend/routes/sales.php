<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Pos\SalesHistoryController;
use App\Http\Controllers\Api\Pos\SalesReportController;

Route::middleware(['auth:sanctum', 'verified', 'tenant',])
    ->prefix('pos')
    ->group(function () {
        Route::get(
            '/sales/summary',
            [SalesReportController::class, 'summary']
        );

        Route::get(
            '/sales',
            [SalesHistoryController::class, 'index']
        );

        Route::get(
            '/sales/{sale}',
            [SalesHistoryController::class, 'show']
        );
    });
