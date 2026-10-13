<?php

use App\Http\Controllers\Api\Pos\SalesHistoryController;
use App\Http\Controllers\Api\Pos\SalesReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant', 'branch:orders.view'])->prefix('pos')->group(function () {
    Route::get('/sales/summary', [SalesReportController::class, 'summary']);
    Route::get('/sales', [SalesHistoryController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('pos')->group(function () {
    Route::get('/sales/{sale}', [SalesHistoryController::class, 'show']);
});
