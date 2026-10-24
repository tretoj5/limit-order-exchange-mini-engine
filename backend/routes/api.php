<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', ProfileController::class);

    // Orders
    Route::get('/orders', [OrderController::class, 'orderbook']);
    Route::get('/orders/mine', [OrderController::class, 'mine']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:30,1');
});
