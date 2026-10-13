<?php

use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('settings/users')->group(function (): void {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::patch('/{membership}', [UserController::class, 'update']);
    Route::delete('/{membership}', [UserController::class, 'destroy']);
    Route::post('/{membership}/suspend', [UserController::class, 'suspend']);
    Route::post('/{membership}/activate', [UserController::class, 'activate']);
    Route::put('/{membership}/branches', [UserController::class, 'branches']);
    Route::put('/{membership}/password', [UserController::class, 'password']);
});
