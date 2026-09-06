<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\BillingInvoiceController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\Settings\TaxController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1');

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'verified', 'tenant'])
    ->get('/navigation', [NavigationController::class, 'index']);

Route::middleware(['auth:sanctum', 'verified', 'tenant'])
    ->prefix('billing')
    ->group(function () {
        Route::get('/', [BillingController::class, 'summary']);
        Route::get('/invoices', [BillingInvoiceController::class, 'index'])->middleware('throttle:30,1');
        Route::post('/checkout', [BillingController::class, 'checkout'])
            ->middleware('throttle:5,1');
        Route::post('/portal', [BillingController::class, 'portal'])
            ->middleware('throttle:10,1');
        Route::post('/cancel', [BillingController::class, 'cancel'])
            ->middleware('throttle:5,1');
        Route::post('/reactivate', [BillingController::class, 'reactivate'])
            ->middleware('throttle:5,1');
    });

Route::middleware(['auth:sanctum', 'verified', 'tenant'])
    ->prefix('settings/taxes')
    ->group(function () {
        Route::get('/', [TaxController::class, 'index']);
        Route::post('/', [TaxController::class, 'store']);
        Route::put('/config', [TaxController::class, 'updateConfig']);
        Route::get('/config', [TaxController::class, 'config']);
        Route::put('/{tax}', [TaxController::class, 'update']);
    });



require __DIR__ . '/pos.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/tickets.php';
require __DIR__ . '/navigation.php';
require __DIR__ . '/business.php';
