<?php

namespace App\Providers;

use App\Auth\RestaurantUserProvider;
use App\Auth\SuperAdminUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('super_admins', function ($app, array $config) {
            return new SuperAdminUserProvider($app['hash'], $config['model']);
        });

        Auth::provider('restaurant_users', function ($app, array $config) {
            return new RestaurantUserProvider($app['hash'], $config['model']);
        });

        View::composer('layouts.super-admin', function ($view): void {
            $view->with('superAdmin', Auth::guard('super_admin')->user());
        });
    }
}
