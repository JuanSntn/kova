<?php

use App\Http\Controllers\Preparation\PreparationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant', 'branch:preparation.view'])
    ->prefix('preparation')
    ->group(function (): void {
        Route::get('/orders', [PreparationController::class, 'index']);
        Route::patch('/items/{item}', [PreparationController::class, 'update'])->whereUuid('item');
    });
