<?php

use App\Http\Controllers\Branches\BranchContextController;
use App\Http\Controllers\Branches\BranchController;
use App\Http\Controllers\Branches\BranchFiscalController;
use App\Http\Controllers\Branches\BranchProductController;
use App\Http\Controllers\Branches\MembershipBranchController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    'account-access',
    'tenant',
])
    ->prefix('branches')
    ->group(function (): void {
        Route::get(
            '/',
            [
                BranchController::class,
                'index',
            ],
        );

        Route::post(
            '/',
            [
                BranchController::class,
                'store',
            ],
        );

        Route::post(
            '/extra/purchase',
            [
                BranchController::class,
                'purchaseAdditional',
            ],
        )->middleware('throttle:billing-sensitive');

        Route::post(
            '/extra/cancel',
            [
                BranchController::class,
                'cancelAdditional',
            ],
        )->middleware('throttle:billing-sensitive');

        Route::get(
            '/{branch}',
            [
                BranchController::class,
                'show',
            ],
        );

        Route::patch(
            '/{branch}',
            [
                BranchController::class,
                'update',
            ],
        );
    });

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->group(function (): void {
    Route::get('/context/branches', BranchContextController::class);
    Route::put('/settings/memberships/{membership}/branches', [MembershipBranchController::class, 'update']);
    Route::post('/branches/{branch}/enable-for-plan', [BranchController::class, 'enable']);
    Route::get('/settings/branches/{branch}/products/{product}', [BranchProductController::class, 'show']);
    Route::put('/settings/branches/{branch}/products/{product}', [BranchProductController::class, 'update']);
    Route::delete('/settings/branches/{branch}/products/{product}', [BranchProductController::class, 'destroy']);
    Route::get('/settings/branches/{branch}/fiscal', [BranchFiscalController::class, 'show']);
    Route::put('/settings/branches/{branch}/fiscal', [BranchFiscalController::class, 'update']);
});
