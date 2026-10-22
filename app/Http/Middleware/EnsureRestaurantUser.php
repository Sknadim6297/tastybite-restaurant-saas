<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\RestaurantSupportAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has(RestaurantSupportAccessService::SESSION_ADMIN_ACCESS_RESTAURANT_ID)) {
            $service = app(RestaurantSupportAccessService::class);
            $restaurant = $service->activeDirectRestaurantFor($request);
            $admin = Auth::guard('super_admin')->user();

            if (
                ! $restaurant
                || ! $service->allowsDirectRoute(
                    (string) $request->route()?->getName(),
                    $request->method(),
                )
            ) {
                if (! $restaurant) {
                    $service->terminateInvalidDirectSession($request, $admin instanceof SuperAdmin ? $admin : null);
                }

                if (! $restaurant) {
                    return redirect()->route('super-admin.restaurants.index')
                        ->with('status', 'Restaurant access has expired or is no longer authorized.');
                }

                abort(403, 'This administrative restaurant session does not permit that action.');
            }

            $owner = $restaurant->owners()->where('status', 'active')->firstOrFail();
            $request->attributes->set('restaurant_support_admin', $admin);
            $request->attributes->set('restaurant_support_owner', $owner->load('restaurant'));
            $request->attributes->set('restaurant_admin_access_restaurant', $restaurant);

            $response = $next($request);
            if ($admin instanceof SuperAdmin) {
                $service->logDirectAction($restaurant, $admin, $request, $response->getStatusCode());
            }

            return $response;
        }

        if ($request->session()->has(RestaurantSupportAccessService::SESSION_REQUEST_ID)) {
            $supportAccess = app(RestaurantSupportAccessService::class)->activeRequestFor($request);
            $routeName = (string) $request->route()?->getName();
            if (
                ! $supportAccess
                || ! app(RestaurantSupportAccessService::class)->allowsRoute($routeName, $request->method())
            ) {
                if (! $supportAccess) {
                    app(RestaurantSupportAccessService::class)->terminateInvalidSession($request);
                    $request->session()->forget([
                        RestaurantSupportAccessService::SESSION_REQUEST_ID,
                        RestaurantSupportAccessService::SESSION_RESTAURANT_ID,
                    ]);
                    $request->session()->regenerate(true);

                    return redirect()->route('super-admin.restaurants.index')
                        ->with('status', 'Restaurant support access has expired or is no longer authorized.');
                }

                abort(403, 'This approved support session does not permit that action.');
            }

            $owner = $supportAccess->restaurant->owners()->where('status', 'active')->firstOrFail();
            $request->attributes->set('restaurant_support_request', $supportAccess);
            $request->attributes->set('restaurant_support_admin', Auth::guard('super_admin')->user());
            $request->attributes->set('restaurant_support_owner', $owner->load('restaurant'));

            $response = $next($request);
            app(RestaurantSupportAccessService::class)->logAction(
                $supportAccess,
                Auth::guard('super_admin')->user(),
                $request,
                $response->getStatusCode(),
            );

            return $response;
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user instanceof User) {
            $user = User::query()->with('restaurant')->find($user->getKey());

            if ($user instanceof User) {
                $guard->setUser($user);
            }
        }

        if (! $user instanceof User) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('restaurant.login');
        }

        $sessionPasswordVersion = $request->session()->get('restaurant_user_password_version');

        if (
            ! $user->isActive()
            || ! $user->restaurant
            || ! $user->restaurant->isActive()
            || ! is_string($sessionPasswordVersion)
            || ! hash_equals(hash('sha256', $user->getAuthPassword()), $sessionPasswordVersion)
        ) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('restaurant.login')->withErrors([
                'email' => 'Your session has expired or your account/restaurant is inactive. Please sign in again.',
            ]);
        }

        return $next($request);
    }
}
