<?php

use App\Http\Controllers\Restaurant\AuthController;
use App\Http\Controllers\Restaurant\AnnouncementController;
use App\Http\Controllers\Restaurant\DashboardController;
use App\Http\Controllers\Restaurant\MenuCategoryController;
use App\Http\Controllers\Restaurant\MenuProductController;
use App\Http\Controllers\Restaurant\SubscriptionStatusController;
use App\Http\Controllers\Restaurant\SupportTicketController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

RateLimiter::for('restaurant-login', function (Request $request) {
    $key = hash('sha256', Str::lower((string) $request->input('email')) . '|' . $request->ip());

    return Limit::perMinute(5)->by($key);
});

RateLimiter::for('restaurant-password-reset', function (Request $request) {
    return Limit::perMinute(5)->by('restaurant-password-reset|' . $request->ip());
});

RateLimiter::for('restaurant-support', function (Request $request) {
    return Limit::perMinute(20)->by('restaurant-support|' . $request->ip());
});

Route::prefix('restaurant')->name('restaurant.')->middleware('restaurant.headers')->group(function () {
    Route::middleware('restaurant.guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:restaurant-login')
            ->name('login.submit');
        Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
        Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
            ->middleware('throttle:restaurant-password-reset')
            ->name('password.email');
        Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])
            ->name('password.reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:restaurant-password-reset')
            ->name('password.update');
    });

    Route::middleware(['restaurant', 'platform.settings'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/subscription', [SubscriptionStatusController::class, 'show'])->name('subscription');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'markRead'])->name('announcements.read');
        Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
        Route::put('/profile/password', [DashboardController::class, 'updatePassword'])->name('profile.password');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::prefix('menu/categories')->name('menu.categories.')
            ->middleware(['restaurant.subscription', 'restaurant.feature:pos'])
            ->controller(MenuCategoryController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{category}/edit', 'edit')->whereNumber('category')->name('edit');
                Route::put('/{category}', 'update')->whereNumber('category')->name('update');
                Route::patch('/{category}/status', 'toggleStatus')->whereNumber('category')->name('status');
                Route::delete('/{category}', 'destroy')->whereNumber('category')->name('destroy');
            });

        Route::prefix('menu/products')->name('menu.products.')
            ->middleware(['restaurant.subscription', 'restaurant.feature:pos'])
            ->controller(MenuProductController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{product}/edit', 'edit')->whereNumber('product')->name('edit');
                Route::put('/{product}', 'update')->whereNumber('product')->name('update');
                Route::delete('/{product}', 'destroy')->whereNumber('product')->name('destroy');
            });

        Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index');
        Route::get('/support/create', [SupportTicketController::class, 'create'])->name('support.create');
        Route::post('/support', [SupportTicketController::class, 'store'])
            ->middleware('throttle:restaurant-support')
            ->name('support.store');
        Route::post('/support/notifications/{notification}/read', [SupportTicketController::class, 'markNotificationRead'])
            ->name('support.notifications.read');
        Route::get('/support/{ticket}', [SupportTicketController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket}/messages', [SupportTicketController::class, 'reply'])
            ->middleware('throttle:restaurant-support')
            ->name('support.messages.store');
        Route::get('/support/{ticket}/attachments/{message}', [SupportTicketController::class, 'attachment'])
            ->name('support.attachments.show');
    });
});
