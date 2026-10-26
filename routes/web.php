<?php

use App\Http\Controllers\PublicUploadController;
use App\Http\Controllers\Restaurant\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('restaurant.login'))
    ->middleware('restaurant.guest');

Route::get('/uploads/{directory}/{restaurantId}/{filename}', [PublicUploadController::class, 'show'])
    ->where('directory', 'restaurant-menu|restaurant-menu-categories|restaurant-branding')
    ->whereNumber('restaurantId')
    ->where('filename', '[A-Za-z0-9._-]+\.[A-Za-z0-9]{3,4}')
    ->name('public.uploads.show');

Route::middleware(['restaurant.headers', 'restaurant', 'restaurant.subscription'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.index');
    Route::post('/pos/orders', [PosController::class, 'complete'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.orders.store');
    Route::get('/pos/held-bills', [PosController::class, 'heldBills'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.held-bills.index');
    Route::post('/pos/held-bills', [PosController::class, 'storeHeldBill'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.held-bills.store');
    Route::post('/pos/held-bills/{order}/resume', [PosController::class, 'resumeHeldBill'])
        ->whereNumber('order')->middleware('restaurant.feature:pos')
        ->name('pos.held-bills.resume');
    Route::delete('/pos/held-bills/{order}', [PosController::class, 'deleteHeldBill'])
        ->whereNumber('order')->middleware('restaurant.feature:pos')
        ->name('pos.held-bills.destroy');
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
