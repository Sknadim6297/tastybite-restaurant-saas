<?php

use App\Http\Controllers\SuperAdmin\AuthController;
use App\Http\Controllers\SuperAdmin\ActivityLogController;
use App\Http\Controllers\SuperAdmin\AnnouncementController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\FeatureManagementController;
use App\Http\Controllers\SuperAdmin\InvoiceController;
use App\Http\Controllers\SuperAdmin\NotificationController;
use App\Http\Controllers\SuperAdmin\PlatformAnalyticsController;
use App\Http\Controllers\SuperAdmin\PaymentManagementController;
use App\Http\Controllers\SuperAdmin\PlatformReportController;
use App\Http\Controllers\SuperAdmin\PlatformSettingsController;
use App\Http\Controllers\SuperAdmin\RestaurantManagementController;
use App\Http\Controllers\SuperAdmin\RestaurantApprovalController;
use App\Http\Controllers\SuperAdmin\RestaurantOwnerController;
use App\Http\Controllers\SuperAdmin\SecurityCenterController;
use App\Http\Controllers\SuperAdmin\SubscriptionManagementController;
use App\Http\Controllers\SuperAdmin\SubscriptionPlanController;
use App\Http\Controllers\SuperAdmin\SystemHealthController;
use App\Http\Controllers\SuperAdmin\SupportTicketController;
use App\Http\Controllers\SuperAdmin\SupportAccessController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

RateLimiter::for('super-admin-login', function (Request $request) {
    $key = hash('sha256', Str::lower((string) $request->input('email')).'|'.$request->ip());

    return Limit::perMinute(AuthController::LOGIN_ATTEMPTS_PER_MINUTE)->by($key);
});

RateLimiter::for('super-admin-password-reset', function (Request $request) {
    return Limit::perMinute(5)->by('super-admin-password-reset|'.$request->ip());
});

RateLimiter::for('super-admin-restaurant-management', function (Request $request) {
    return Limit::perMinute(30)->by('super-admin-restaurant-management|'.$request->ip());
});

RateLimiter::for('super-admin-subscription-management', function (Request $request) {
    return Limit::perMinute(30)->by('super-admin-subscription-management|'.$request->ip());
});

RateLimiter::for('super-admin-owner-management', function (Request $request) {
    return Limit::perMinute(30)->by('super-admin-owner-management|'.$request->ip());
});

Route::prefix('super-admin')->name('super-admin.')->middleware('super-admin.headers')->group(function () {
    Route::middleware('super-admin.guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:super-admin-login')
            ->name('login.submit');
        Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
        Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
            ->middleware('throttle:super-admin-password-reset')
            ->name('password.email');
        Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])
            ->name('password.reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:super-admin-password-reset')
            ->name('password.update');
    });

    Route::middleware(['super-admin', 'platform.settings'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::get('/analytics', [PlatformAnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/security-center', [SecurityCenterController::class, 'index'])->name('security-center.index');
        Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system-health.index');
        Route::get('/support-access', [SupportAccessController::class, 'index'])->name('support-access.index');
        Route::get('/support-access/create', [SupportAccessController::class, 'create'])->name('support-access.create');
        Route::post('/support-access', [SupportAccessController::class, 'store'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('support-access.store');
        Route::get('/support-access/{supportAccessRequest}', [SupportAccessController::class, 'show'])->name('support-access.show');
        Route::post('/support-access/{supportAccessRequest}/decision', [SupportAccessController::class, 'decide'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('support-access.decide');
        Route::post('/support-access/{supportAccessRequest}/cancel', [SupportAccessController::class, 'cancel'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('support-access.cancel');
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::post('/announcements/{announcement}/archive', [AnnouncementController::class, 'archive'])->name('announcements.archive');

        Route::get('/features', [FeatureManagementController::class, 'index'])->name('features.index');
        Route::put('/features/{feature}', [FeatureManagementController::class, 'update'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('features.update');

        Route::get('/support-tickets', [SupportTicketController::class, 'index'])->name('support-tickets.index');
        Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support-tickets.show');
        Route::put('/support-tickets/{ticket}', [SupportTicketController::class, 'update'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('support-tickets.update');
        Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('support-tickets.messages.store');
        Route::get('/support-tickets/{ticket}/attachments/{message}', [SupportTicketController::class, 'attachment'])
            ->name('support-tickets.attachments.show');

        Route::get('/reports', [PlatformReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/restaurants', [PlatformReportController::class, 'restaurants'])->name('reports.restaurants');
        Route::get('/reports/subscriptions', [PlatformReportController::class, 'subscriptions'])->name('reports.subscriptions');
        Route::get('/reports/payments', [PlatformReportController::class, 'payments'])->name('reports.payments');
        Route::get('/reports/revenue', [PlatformReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('/reports/export', [PlatformReportController::class, 'export'])->name('reports.export');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::get('/activity-logs/export', [ActivityLogController::class, 'export'])->name('activity-logs.export');
        Route::get('/activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->name('activity-logs.show');

        Route::get('/settings', [PlatformSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/branding', [PlatformSettingsController::class, 'updateBranding'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('settings.branding');
        Route::post('/settings/{group}', [PlatformSettingsController::class, 'update'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('settings.update');
        Route::get('/settings/branding/{kind}', [PlatformSettingsController::class, 'branding'])->name('settings.branding.show');
        Route::put('/settings/security/profile', [PlatformSettingsController::class, 'updateProfile'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('settings.security.profile');
        Route::put('/settings/security/password', [PlatformSettingsController::class, 'updatePassword'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('settings.security.password');

        Route::get('/payments', [PaymentManagementController::class, 'index'])->name('payments.index');
        Route::get('/payments/create', [PaymentManagementController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentManagementController::class, 'store'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('payments.store');
        Route::get('/payments/reports', [PaymentManagementController::class, 'reports'])->name('payments.reports');
        Route::get('/payments/export', [PaymentManagementController::class, 'export'])->name('payments.export');
        Route::get('/payments/{payment}', [PaymentManagementController::class, 'show'])->name('payments.show');
        Route::get('/payments/{payment}/proof', [PaymentManagementController::class, 'proof'])->name('payments.proof');
        Route::post('/payments/{payment}/approve', [PaymentManagementController::class, 'approve'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('payments.approve');
        Route::post('/payments/{payment}/reject', [PaymentManagementController::class, 'reject'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('payments.reject');
        Route::post('/payments/{payment}/refunds', [PaymentManagementController::class, 'refund'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('payments.refunds.store');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
        Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index'])->name('subscription-plans.index');
        Route::get('/subscription-plans/create', [SubscriptionPlanController::class, 'create'])->name('subscription-plans.create');
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscription-plans.store');
        Route::get('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'show'])->name('subscription-plans.show');
        Route::get('/subscription-plans/{subscriptionPlan}/edit', [SubscriptionPlanController::class, 'edit'])->name('subscription-plans.edit');
        Route::put('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscription-plans.update');
        Route::post('/subscription-plans/{subscriptionPlan}/status', [SubscriptionPlanController::class, 'updateStatus'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscription-plans.status');

        Route::get('/subscriptions', [SubscriptionManagementController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/create', [SubscriptionManagementController::class, 'create'])->name('subscriptions.create');
        Route::post('/subscriptions', [SubscriptionManagementController::class, 'store'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscriptions.store');
        Route::get('/subscriptions/expiring', [SubscriptionManagementController::class, 'expiring'])->name('subscriptions.expiring');
        Route::get('/subscriptions/history', [SubscriptionManagementController::class, 'history'])->name('subscriptions.history');
        Route::get('/subscriptions/{restaurantSubscription}', [SubscriptionManagementController::class, 'show'])->name('subscriptions.show');
        Route::post('/subscriptions/{restaurantSubscription}/renew', [SubscriptionManagementController::class, 'renew'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscriptions.renew');
        Route::post('/subscriptions/{restaurantSubscription}/extend', [SubscriptionManagementController::class, 'extend'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscriptions.extend');
        Route::post('/subscriptions/{restaurantSubscription}/status', [SubscriptionManagementController::class, 'updateStatus'])
            ->middleware('throttle:super-admin-subscription-management')
            ->name('subscriptions.status');

        Route::get('/restaurants', [RestaurantManagementController::class, 'index'])->name('restaurants.index');
        Route::get('/restaurant-approvals', [RestaurantApprovalController::class, 'index'])->name('restaurant-approvals.index');
        Route::get('/restaurant-approvals/{restaurant}', [RestaurantApprovalController::class, 'show'])->name('restaurant-approvals.show');
        Route::put('/restaurant-approvals/{restaurant}', [RestaurantApprovalController::class, 'decide'])
            ->middleware('throttle:super-admin-restaurant-management')
            ->name('restaurant-approvals.decide');
        Route::get('/restaurants/create', [RestaurantManagementController::class, 'create'])->name('restaurants.create');
        Route::post('/restaurants', [RestaurantManagementController::class, 'store'])
            ->middleware('throttle:super-admin-restaurant-management')
            ->name('restaurants.store');
        Route::get('/restaurants/{restaurant}', [RestaurantManagementController::class, 'show'])->name('restaurants.show');
        Route::get('/restaurants/{restaurant}/edit', [RestaurantManagementController::class, 'edit'])->name('restaurants.edit');
        Route::put('/restaurants/{restaurant}', [RestaurantManagementController::class, 'update'])->name('restaurants.update');
        Route::post('/restaurants/{restaurant}/status', [RestaurantManagementController::class, 'toggleStatus'])->name('restaurants.toggle-status');
        Route::post('/restaurants/{restaurant}/owners/{user}/status', [RestaurantManagementController::class, 'toggleOwnerStatus'])
            ->middleware('throttle:super-admin-owner-management')
            ->name('restaurants.owners.toggle-status');
        Route::post('/restaurants/{restaurant}/owners/{user}/reset-password', [RestaurantManagementController::class, 'resetOwnerPassword'])
            ->middleware('throttle:super-admin-owner-management')
            ->name('restaurants.owners.reset-password');

        Route::get('/restaurant-owners', [RestaurantOwnerController::class, 'index'])->name('restaurant-owners.index');
        Route::post('/restaurant-owners', fn () => abort(404));
        Route::get('/restaurant-owners/{user}', [RestaurantOwnerController::class, 'show'])->name('restaurant-owners.show');
        Route::get('/restaurant-owners/{user}/edit', [RestaurantOwnerController::class, 'edit'])->name('restaurant-owners.edit');
        Route::put('/restaurant-owners/{user}', [RestaurantOwnerController::class, 'update'])
            ->middleware('throttle:super-admin-owner-management')
            ->name('restaurant-owners.update');
        Route::post('/restaurant-owners/{user}/status', [RestaurantOwnerController::class, 'updateStatus'])
            ->middleware('throttle:super-admin-owner-management')
            ->name('restaurant-owners.status');
        Route::post('/restaurant-owners/{user}/reset-password', [RestaurantOwnerController::class, 'sendPasswordResetLink'])
            ->middleware('throttle:super-admin-owner-management')
            ->name('restaurant-owners.reset-password');

        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
