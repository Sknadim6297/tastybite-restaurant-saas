<?php

use App\Console\Commands\CreateSuperAdmin;
use App\Console\Commands\DisableSuperAdmin;
use App\Console\Commands\NotifyExpiringSubscriptions;
use App\Console\Commands\ReconcileRestaurantSubscriptions;
use App\Http\Middleware\ApplyPlatformSettings;
use App\Http\Middleware\EnsureRestaurantSubscription;
use App\Http\Middleware\EnsureRestaurantFeature;
use App\Http\Middleware\EnsureRestaurantUser;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RedirectAuthenticatedRestaurantUser;
use App\Http\Middleware\RedirectAuthenticatedSuperAdmin;
use App\Http\Middleware\SetRestaurantSecurityHeaders;
use App\Http\Middleware\SetSuperAdminSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateSuperAdmin::class,
        DisableSuperAdmin::class,
        NotifyExpiringSubscriptions::class,
        ReconcileRestaurantSubscriptions::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'super-admin' => EnsureSuperAdmin::class,
            'super-admin.guest' => RedirectAuthenticatedSuperAdmin::class,
            'super-admin.headers' => SetSuperAdminSecurityHeaders::class,
            'platform.settings' => ApplyPlatformSettings::class,
            'restaurant' => EnsureRestaurantUser::class,
            'restaurant.subscription' => EnsureRestaurantSubscription::class,
            'restaurant.feature' => EnsureRestaurantFeature::class,
            'restaurant.guest' => RedirectAuthenticatedRestaurantUser::class,
            'restaurant.headers' => SetRestaurantSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
