<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\BillingInvoiceController;
use App\Http\Controllers\Billing\StampUsageController;
use App\Http\Controllers\Billing\SubscriptionController;
use App\Http\Controllers\Fiscal\InvoiceController as FiscalInvoiceController;
use App\Http\Controllers\Fiscal\SatCatalogController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\Settings\FiscalProfileController;
use App\Http\Controllers\Settings\FiscalSeriesController;
use App\Http\Controllers\Settings\TaxController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('/staff/{branchLoginCode}', [StaffAuthController::class, 'show'])->middleware('throttle:staff-login-read');
    Route::post('/staff/{branchLoginCode}/login', [StaffAuthController::class, 'login'])->middleware('throttle:staff-login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:3,1,password-forgot:');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1,password-reset:');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1,verification-resend:');
    Route::get('/email/verification-status', [EmailVerificationController::class, 'status'])->middleware('throttle:30,1,verification-status:');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::middleware(['auth:sanctum', 'account-access'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->get('/navigation', [NavigationController::class, 'index']);

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('billing')->group(function () {
    Route::get('/', [BillingController::class, 'summary']);
    Route::get('/offers', [SubscriptionController::class, 'offers']);
    Route::get('/changes', [SubscriptionController::class, 'changes']);
    Route::delete('/changes/{id}', [SubscriptionController::class, 'cancelChange'])->middleware('throttle:billing-standard');
    Route::post('/addons', [SubscriptionController::class, 'add'])->middleware('throttle:billing-standard');
    Route::patch('/addons/{id}', [SubscriptionController::class, 'reduce'])->middleware('throttle:billing-standard');
    Route::get('/invoices', [BillingInvoiceController::class, 'index'])->middleware('throttle:30,1');
    Route::get('/stamp-usage', [StampUsageController::class, 'show'])->middleware('throttle:30,1');
    Route::put('/stamp-overage', [StampUsageController::class, 'update'])->middleware('throttle:billing-sensitive');
    Route::post('/checkout', [BillingController::class, 'checkout'])->middleware('throttle:billing-sensitive');
    Route::post('/checkout/cancel-pending', [BillingController::class, 'cancelPendingCheckout'])->middleware('throttle:billing-sensitive');
    Route::post('/reconcile', [BillingController::class, 'reconcile'])->middleware('throttle:billing-sensitive');
    Route::post('/portal', [BillingController::class, 'portal'])->middleware('throttle:billing-standard');
    Route::post('/cancel', [BillingController::class, 'cancel'])->middleware('throttle:billing-sensitive');
    Route::post('/reactivate', [BillingController::class, 'reactivate'])->middleware('throttle:billing-sensitive');
});

/*
|--------------------------------------------------------------------------
| Configuración fiscal
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('settings/fiscal')->group(function () {
    Route::get('/profile', [FiscalProfileController::class, 'show']);
    Route::put('/profile', [FiscalProfileController::class, 'update'])->middleware('throttle:10,1');
    Route::post('/certificates', [FiscalProfileController::class, 'certificate'])->middleware('throttle:5,1');
    Route::get('/status', [FiscalProfileController::class, 'show']);
    Route::post('/provider/sync', [FiscalProfileController::class, 'sync'])->middleware('throttle:3,1');

    /*
    |--------------------------------------------------------------------------
    | Series fiscales
    |--------------------------------------------------------------------------
    */

    Route::get('/series', [FiscalSeriesController::class, 'index']);
    Route::post('/series', [FiscalSeriesController::class, 'store'])->middleware('throttle:10,1');
    Route::put('/series/default', [FiscalSeriesController::class, 'setDefault'])->middleware('throttle:10,1');
});

Route::middleware(['auth:sanctum', 'account-access', 'tenant', 'throttle:60,1'])->prefix('sat/catalogs')->group(function () {
    Route::get('/tax-regimes', [SatCatalogController::class, 'regimes']);
    Route::get('/postal-codes/{code}', [SatCatalogController::class, 'postal']);
    Route::get('/product-services', [SatCatalogController::class, 'products']);
    Route::get('/units', [SatCatalogController::class, 'units']);
    Route::get('/tax-objects', [SatCatalogController::class, 'objects']);
    Route::get('/cfdi-uses', [SatCatalogController::class, 'uses']);
});

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('fiscal/invoices')->group(function () {
    Route::get('/', [FiscalInvoiceController::class, 'index']);
    Route::get('/{invoice}', [FiscalInvoiceController::class, 'show']);
    Route::post('/{invoice}/cancel', [FiscalInvoiceController::class, 'cancel'])->middleware('throttle:5,1');
    Route::get('/{invoice}/download', [FiscalInvoiceController::class, 'download'])->middleware('signed')->name('fiscal.invoice.download');
    Route::get('/{invoice}/download/pdf', [FiscalInvoiceController::class, 'downloadPdf'])->middleware('signed')->name('fiscal.invoice.download.pdf');
});

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('settings/taxes')->group(function () {
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
require __DIR__ . '/invoices.php';
require __DIR__ . '/branches.php';
require __DIR__ . '/users.php';

require __DIR__ . '/cash.php';

require __DIR__ . '/inventory.php';
require __DIR__ . '/preparation.php';
