<?php

use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Inventory\InventoryExcelController;
use App\Http\Controllers\Inventory\InventoryReportController;
use App\Http\Controllers\Inventory\SupplyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])->prefix('inventory')->group(function () {
    Route::get('/excel', [InventoryExcelController::class, 'download']);
    Route::post('/imports', [InventoryExcelController::class, 'preview']);
    Route::post('/imports/{import}/commit', [InventoryExcelController::class, 'commit'])->whereUuid('import');
    Route::get('/catalog', [InventoryController::class, 'catalog']);
    Route::get('/report', [InventoryReportController::class, 'show']);
    Route::get('/report/pdf', [InventoryReportController::class, 'download']);
    Route::post('/categories', [InventoryController::class, 'category']);
    Route::put('/categories/{category}', [InventoryController::class, 'category'])->whereUuid('category');
    Route::post('/recipes', [InventoryController::class, 'recipe']);
    Route::put('/recipes/{recipe}', [InventoryController::class, 'recipe'])->whereUuid('recipe');
    Route::get('/balances', [InventoryController::class, 'balances']);
    Route::get('/movements', [InventoryController::class, 'movements']);
    Route::post('/operations', [InventoryController::class, 'operation']);
    Route::get('/products', [InventoryController::class, 'products']);
    Route::get('/products/{product}', [InventoryController::class, 'productLink'])->whereUuid('product');
    Route::put('/products/{product}', [InventoryController::class, 'productLink'])->whereUuid('product');
    Route::get('/supplies', [SupplyController::class, 'index']);
    Route::post('/supplies', [SupplyController::class, 'store']);
    Route::put('/supplies/{supply}', [SupplyController::class, 'update'])->whereUuid('supply');
});
