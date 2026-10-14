<?php

use App\Http\Controllers\Restaurant\PosController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['restaurant.headers', 'restaurant', 'restaurant.subscription'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])
        ->middleware('restaurant.feature:pos')
        ->name('pos.index');
});

require __DIR__.'/super-admin.php';
require __DIR__.'/restaurant.php';
