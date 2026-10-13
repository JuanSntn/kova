<?php

use App\Http\Controllers\Api\Pos\CategoryController;
use App\Http\Controllers\Api\Pos\OrderController;
use App\Http\Controllers\Api\Pos\OrderTicketController;
use App\Http\Controllers\Api\Pos\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'account-access', 'tenant'])
    ->prefix('pos')
    ->group(function () {
        Route::get('/modifiers', [\App\Http\Controllers\Api\Pos\ModifierController::class, 'index']);
        Route::post('/modifiers', [\App\Http\Controllers\Api\Pos\ModifierController::class, 'save']);
        Route::put('/modifiers/{group}', [\App\Http\Controllers\Api\Pos\ModifierController::class, 'save'])->whereUuid('group');
        Route::get('/products/{product}/modifiers', [\App\Http\Controllers\Api\Pos\ModifierController::class, 'product'])->whereUuid('product');
        Route::put('/products/{product}/modifiers', [\App\Http\Controllers\Api\Pos\ModifierController::class, 'product'])->whereUuid('product');

        Route::get('/categories', [CategoryController::class, 'index']);

        Route::post('/categories', [CategoryController::class, 'store']);

        Route::put('/categories/{category}', [CategoryController::class, 'update'])->whereUuid('category');

        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->whereUuid('category');

        Route::get('/products', [ProductController::class, 'index']);

        Route::post('/products', [ProductController::class, 'store']);

        Route::put('/products/{product}', [ProductController::class, 'update'])->whereUuid('product');

        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereUuid('product');

        Route::get('/orders/{order}/ticket', [OrderTicketController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/checkouts/{idempotencyKey}', [OrderController::class, 'recover'])->whereUuid('idempotencyKey');
    });

Route::middleware(['auth:sanctum', 'account-access', 'tenant', 'branch:orders.create'])->prefix('pos')->group(function () {
    Route::get('/catalog/products', [ProductController::class, 'operationalIndex']);
    Route::get('/catalog/categories', [CategoryController::class, 'operationalIndex']);
    Route::post('/checkout/quote', [OrderController::class, 'quote']);
});
