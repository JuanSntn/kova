<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Pos\CategoryController;
use App\Http\Controllers\Api\Pos\OrderController;
use App\Http\Controllers\Api\Pos\ProductController;
use App\Http\Controllers\Api\Pos\OrderTicketController;

Route::middleware(['auth:sanctum', 'verified', 'tenant',])
    ->prefix('pos')
    ->group(function () {

        Route::get('/categories', [CategoryController::class, 'index']);

        Route::post('/categories', [CategoryController::class, 'store']);

        Route::put('/categories/{category}', [CategoryController::class, 'update']);

        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::get('/products', [ProductController::class, 'index']);

        Route::post('/products', [ProductController::class, 'store']);

        Route::put('/products/{product}', [ProductController::class, 'update']);

        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('/orders', [OrderController::class, 'store']);

        Route::get('/orders/{order}/ticket', [OrderTicketController::class, 'show']);
    });
