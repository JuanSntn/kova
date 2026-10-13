<?php

use App\Http\Controllers\Cash\CashController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('cash')->group(function () {
    Route::get('context', [CashController::class, 'context']);
    Route::get('registers', [CashController::class, 'registers']);
    Route::post('registers', [CashController::class, 'saveRegister']);
    Route::patch('registers/{register}', [CashController::class, 'saveRegister'])->whereUuid('register');
    Route::get('shifts', [CashController::class, 'index']);
    Route::get('shifts/{shift}', [CashController::class, 'show'])->whereUuid('shift');
    Route::post('shifts', [CashController::class, 'mutate'])->defaults('operation', 'OPEN_SHIFT');
    Route::post('shifts/{shift}/movements', [CashController::class, 'mutate'])->whereUuid('shift')->defaults('operation', 'CASH_MOVEMENT');
    Route::post('shifts/{shift}/counts', [CashController::class, 'mutate'])->whereUuid('shift')->defaults('operation', 'COUNT_SHIFT');
    Route::post('shifts/{shift}/close', [CashController::class, 'mutate'])->whereUuid('shift')->defaults('operation', 'CLOSE_SHIFT');
});
