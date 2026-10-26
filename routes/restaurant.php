<?php

use App\Http\Controllers\Restaurant\AuthController;
use App\Http\Controllers\Restaurant\AnnouncementController;
use App\Http\Controllers\Restaurant\DashboardController;
use App\Http\Controllers\Restaurant\InventoryController;
use App\Http\Controllers\Restaurant\MenuCategoryController;
use App\Http\Controllers\Restaurant\MenuProductController;
use App\Http\Controllers\Restaurant\PosController;
use App\Http\Controllers\Restaurant\RestaurantOrderController;
use App\Http\Controllers\Restaurant\TableManagementController;
use App\Http\Controllers\Restaurant\SubscriptionStatusController;
use App\Http\Controllers\Restaurant\SupportTicketController;
use App\Http\Controllers\Restaurant\VendorController;
use App\Http\Controllers\Restaurant\VendorGoodsReceiptController;
use App\Http\Controllers\Restaurant\VendorPurchaseOrderController;
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

RateLimiter::for('restaurant-payment-submission', function (Request $request) {
    $userId = $request->user('web')?->getAuthIdentifier() ?? 'guest';

    return Limit::perMinute(5)->by('restaurant-payment-submission|'.$userId.'|'.$request->ip());
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
        Route::get('/subscription/invoices/{invoice}/pdf', [SubscriptionStatusController::class, 'invoicePdf'])
            ->whereNumber('invoice')
            ->name('subscription.invoices.pdf');
        Route::post('/subscription/{restaurantSubscription}/payments', [SubscriptionStatusController::class, 'submitPayment'])
            ->whereNumber('restaurantSubscription')
            ->middleware('throttle:restaurant-payment-submission')
            ->name('subscription.payments.store');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'markRead'])->name('announcements.read');
        Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
        Route::put('/profile/branding', [DashboardController::class, 'updateBranding'])->name('profile.branding');
        Route::put('/profile/password', [DashboardController::class, 'updatePassword'])->name('profile.password');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::prefix('inventory')->name('inventory.')
            ->middleware('restaurant.subscription')
            ->controller(InventoryController::class)
            ->group(function (): void {
                Route::get('/', 'overview')->name('overview');
                Route::get('/items', 'items')->name('items.index');
                Route::get('/items/export', 'exportItems')->name('items.export');
                Route::get('/items/create', 'createItem')->name('items.create');
                Route::post('/items', 'storeItem')->name('items.store');
                Route::get('/items/{item}', 'showItem')->whereNumber('item')->name('items.show');
                Route::get('/items/{item}/edit', 'editItem')->whereNumber('item')->name('items.edit');
                Route::put('/items/{item}', 'updateItem')->whereNumber('item')->name('items.update');
                Route::delete('/items/{item}', 'archiveItem')->whereNumber('item')->name('items.archive');
                Route::get('/categories', 'categories')->name('categories.index');
                Route::post('/categories', 'storeCategory')->name('categories.store');
                Route::put('/categories/{category}', 'updateCategory')->whereNumber('category')->name('categories.update');
                Route::delete('/categories/{category}', 'deleteCategory')->whereNumber('category')->name('categories.delete');
                Route::get('/units', 'units')->name('units.index');
                Route::post('/units', 'storeUnit')->name('units.store');
                Route::post('/units/conversions', 'storeConversion')->name('units.conversions.store');
                Route::delete('/units/{unit}', 'deleteUnit')->whereNumber('unit')->name('units.delete');
                Route::get('/locations', 'locations')->name('locations.index');
                Route::post('/locations', 'storeLocation')->name('locations.store');
                Route::put('/locations/{location}', 'updateLocation')->whereNumber('location')->name('locations.update');
                Route::delete('/locations/{location}', 'deleteLocation')->whereNumber('location')->name('locations.delete');
                Route::get('/stock/create', 'movementForm')->name('stock.create');
                Route::post('/stock/opening', 'openingStock')->name('stock.opening');
                Route::post('/stock/in', 'stockIn')->name('stock.in');
                Route::post('/stock/out', 'stockOut')->name('stock.out');
                Route::post('/stock/transfers', 'transfer')->name('stock.transfers.store');
                Route::post('/stock/adjustments', 'adjustStock')->name('stock.adjustments.store');
                Route::get('/counts', 'counts')->name('counts.index');
                Route::post('/counts', 'startCount')->name('counts.store');
                Route::get('/counts/{count}', 'showCount')->whereNumber('count')->name('counts.show');
                Route::put('/counts/{count}', 'updateCount')->whereNumber('count')->name('counts.update');
                Route::post('/counts/{count}/post', 'postCount')->whereNumber('count')->name('counts.post');
                Route::get('/wastage', 'wastage')->name('wastage.index');
                Route::post('/wastage', 'storeWastage')->name('wastage.store');
                Route::get('/ledger', 'ledger')->name('ledger');
                Route::get('/ledger/export', 'export')->name('export');
                Route::get('/batches', 'batches')->name('batches');
                Route::get('/vendor-mappings', 'vendorMappings')->name('vendor-mappings.index');
                Route::put('/vendor-mappings/{purchaseOrderItem}', 'storeVendorMapping')->whereNumber('purchaseOrderItem')->name('vendor-mappings.update');
                Route::get('/settings', 'settings')->name('settings');
                Route::put('/settings', 'updateSettings')->name('settings.update');
                Route::get('/recipes', 'recipes')->name('recipes.index');
                Route::post('/recipes', 'storeRecipe')->name('recipes.store');
                Route::post('/recipes/{recipe}/activate', 'activateRecipe')->whereNumber('recipe')->name('recipes.activate');
                Route::get('/reports', 'reports')->name('reports');
            });

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

        Route::prefix('orders')->name('orders.')
            ->middleware(['restaurant.subscription', 'restaurant.feature:pos'])
            ->controller(RestaurantOrderController::class)
            ->group(function (): void {
                Route::get('/', 'overview')->name('overview');
                Route::get('/all', 'all')->name('all');
                Route::get('/dine-in', 'dineIn')->name('dine-in');
                Route::get('/fulfillment', 'fulfillment')->name('fulfillment');
                Route::get('/billing', 'billing')->name('billing');
                Route::get('/reports', 'reports')->name('reports');
                Route::get('/reports/export', 'export')->name('reports.export');
                Route::get('/{order}/receipt', 'receipt')->whereNumber('order')->name('receipt');
                Route::get('/{order}/invoice/pdf', 'invoicePdf')->whereNumber('order')->name('invoice.pdf');
                Route::get('/{order}/invoice', 'invoice')->whereNumber('order')->name('invoice');
                Route::get('/{order}/kot', 'showKot')->whereNumber('order')->name('kot');
                Route::post('/{order}/kot', 'reprintKot')->whereNumber('order')->name('kot.reprint');
                Route::get('/{order}', 'show')->whereNumber('order')->name('show');
                Route::patch('/{order}/status', 'updateStatus')->whereNumber('order')->name('status');
                Route::post('/{order}/refund', 'recordRefund')->whereNumber('order')->name('refund');
                Route::post('/{order}/payments/reconcile', [PosController::class, 'reconcilePayment'])->whereNumber('order')->name('payment.reconcile');
                Route::post('/{order}/cancel', [PosController::class, 'cancel'])->whereNumber('order')->name('cancel');
            });

        Route::prefix('tables')->name('tables.')
            ->middleware(['restaurant.subscription', 'restaurant.feature:pos'])
            ->controller(TableManagementController::class)
            ->group(function (): void {
                Route::get('/', 'overview')->name('overview');
                Route::get('/manage', 'manage')->name('manage');
                Route::get('/list', 'index')->name('index');
                Route::get('/floors', 'floors')->name('floors');
                Route::get('/reservations', 'reservations')->name('reservations');
                Route::get('/operations', 'operations')->name('operations');
                Route::get('/reports', 'reports')->name('reports');
                Route::get('/reports/export', 'exportReports')->name('reports.export');
                Route::get('/status-feed', 'statusFeed')->name('status-feed');
                Route::post('/floors', 'storeFloor')->name('floors.store');
                Route::put('/floors/{floor}', 'updateFloor')->whereNumber('floor')->name('floors.update');
                Route::delete('/floors/{floor}', 'archiveFloor')->whereNumber('floor')->name('floors.archive');
                Route::post('/sections', 'storeSection')->name('sections.store');
                Route::put('/sections/{section}', 'updateSection')->whereNumber('section')->name('sections.update');
                Route::delete('/sections/{section}', 'archiveSection')->whereNumber('section')->name('sections.archive');
                Route::post('/list', 'storeTable')->name('store');
                Route::post('/list/bulk', 'bulkTables')->name('bulk');
                Route::put('/list/{table}', 'updateTable')->whereNumber('table')->name('update');
                Route::delete('/list/{table}', 'archiveTable')->whereNumber('table')->name('archive');
                Route::patch('/{table}/status', 'status')->whereNumber('table')->name('status');
                Route::post('/operations/transfer', 'transfer')->name('transfer');
                Route::post('/operations/merge', 'merge')->name('merge');
                Route::post('/operations/split', 'split')->name('split');
                Route::post('/reservations', 'storeReservation')->name('reservations.store');
                Route::put('/reservations/{reservation}', 'updateReservation')->whereNumber('reservation')->name('reservations.update');
                Route::patch('/reservations/{reservation}/status', 'reservationStatus')->whereNumber('reservation')->name('reservations.status');
                Route::get('/{table}', 'show')->whereNumber('table')->name('show');
            });

        Route::prefix('vendors')->name('vendors.')
            ->middleware('restaurant.subscription')
            ->controller(VendorController::class)
            ->group(function (): void {
                Route::get('/', 'overview')->name('overview');
                Route::get('/list', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{vendor}/edit', 'edit')->whereNumber('vendor')->name('edit');
                Route::put('/{vendor}', 'update')->whereNumber('vendor')->name('update');
                Route::patch('/{vendor}/status', 'status')->whereNumber('vendor')->name('status');
                Route::delete('/{vendor}', 'archive')->whereNumber('vendor')->name('archive');
                Route::get('/{vendor}', 'show')->whereNumber('vendor')->name('show');
            });

        Route::prefix('vendors/purchase-orders')->name('vendors.purchase-orders.')
            ->middleware('restaurant.subscription')
            ->controller(VendorPurchaseOrderController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{purchaseOrder}/edit', 'edit')->whereNumber('purchaseOrder')->name('edit');
                Route::put('/{purchaseOrder}', 'update')->whereNumber('purchaseOrder')->name('update');
                Route::post('/{purchaseOrder}/confirm', 'confirm')->whereNumber('purchaseOrder')->name('confirm');
                Route::post('/{purchaseOrder}/cancel', 'cancel')->whereNumber('purchaseOrder')->name('cancel');
                Route::get('/{purchaseOrder}/print', 'print')->whereNumber('purchaseOrder')->name('print');
                Route::get('/{purchaseOrder}/pdf', 'pdf')->whereNumber('purchaseOrder')->name('pdf');
                Route::get('/{purchaseOrder}', 'show')->whereNumber('purchaseOrder')->name('show');
            });

        Route::prefix('vendors/goods-received')->name('vendors.goods-received.')
            ->middleware('restaurant.subscription')
            ->controller(VendorGoodsReceiptController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/{purchaseOrder}/create', 'create')->whereNumber('purchaseOrder')->name('create');
                Route::post('/{purchaseOrder}', 'store')->whereNumber('purchaseOrder')->name('store');
                Route::get('/receipt/{receipt}', 'show')->whereNumber('receipt')->name('show');
                Route::post('/receipt/{receipt}/inventory', 'postInventory')->whereNumber('receipt')->name('inventory.post');
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
