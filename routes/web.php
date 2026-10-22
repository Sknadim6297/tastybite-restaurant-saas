<?php

use App\Http\Controllers\Restaurant\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('restaurant.login'))
    ->middleware('restaurant.guest');

Route::middleware(['restaurant.headers', 'restaurant', 'restaurant.subscription'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.index');
    Route::post('/pos/orders', [PosController::class, 'complete'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.orders.store');
    Route::get('/pos/orders', [PosController::class, 'orders'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.orders.index');
    Route::post('/pos/orders/{order}/cancel', [PosController::class, 'cancel'])
        ->whereNumber('order')
        ->middleware('restaurant.feature:pos')
        ->name('pos.orders.cancel');
});

require __DIR__.'/super-admin.php';
require __DIR__.'/restaurant.php';
