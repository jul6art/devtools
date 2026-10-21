<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store')->middleware('can:create,'.Order::class);
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show')->middleware('can:view,order');
});

// No name: its identifier comes from its method and URI.
Route::get('/about', AboutController::class);
