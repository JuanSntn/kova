<?php


use App\Http\Controllers\NavigationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])
    ->get('/navigation', [NavigationController::class, 'index']);
