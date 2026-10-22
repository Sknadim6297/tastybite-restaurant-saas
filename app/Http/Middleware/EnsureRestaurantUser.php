<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\RestaurantDashboardAccessService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has(RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER)) {
            $service = app(RestaurantDashboardAccessService::class);
            $restaurant = $service->restaurantFor($request);
            $admin = Auth::guard('super_admin')->user();

            if (
                ! $restaurant
                || ! $service->allowsRoute((string) $request->route()?->getName(), $request->method())
            ) {
                if (! $restaurant) {
                    $service->terminateInvalidSession($request, $admin instanceof SuperAdmin ? $admin : null);
                }

                if (! $restaurant) {
                    return redirect()->route('super-admin.restaurants.index')
                        ->with('status', 'Restaurant access has expired or is no longer authorized.');
                }

                abort(403, 'This administrative restaurant session does not permit that action.');
            }

            $owner = $restaurant->owners()->where('status', 'active')->firstOrFail();
            $request->attributes->set('restaurant_admin_access_admin', $admin);
            $request->attributes->set('restaurant_admin_access_owner', $owner->load('restaurant'));
            $request->attributes->set('restaurant_admin_access_restaurant', $restaurant);
            $request->attributes->set(
                'restaurant_admin_access_token',
                $request->query(RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER),
            );
            $response = $next($request);
            if ($admin instanceof SuperAdmin) {
                $service->logAction($restaurant, $admin, $request, $response->getStatusCode());
            }
            if ($response instanceof RedirectResponse) {
                $this->preserveAccessToken($response, $request);
            }

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
            if (Auth::guard('super_admin')->user() instanceof SuperAdmin) {
                return redirect()->route('super-admin.restaurants.index')
                    ->with('status', 'Use Go to Dashboard to open an authorized restaurant access tab.');
            }

            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('restaurant.login');
        }

        $sessionPasswordVersion = $request->session()->get('restaurant_user_password_version');

        if (
            ! $user->isActive()
            || ! $user->restaurant
            || str_starts_with($user->restaurant->slug, 'demo-tb-2026-')
            || ! $user->restaurant->isActive()
            || ! is_string($sessionPasswordVersion)
            || ! hash_equals(hash('sha256', $user->getAuthPassword()), $sessionPasswordVersion)
        ) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('restaurant.login')->withErrors([
                'email' => 'Your session has expired or this account is unavailable. Please sign in again.',
            ]);
        }

        return $next($request);
    }

    private function preserveAccessToken(RedirectResponse $response, Request $request): void
    {
        $target = $response->getTargetUrl();
        $parts = parse_url($target);
        if (
            $parts === false
            || (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0)
        ) {
            return;
        }

        parse_str($parts['query'] ?? '', $query);
        $query[RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER] = $request->query(
            RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER,
        );

        $authority = isset($parts['scheme'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';
        $response->setTargetUrl(
            $authority.($parts['path'] ?? '/')
            .'?'.http_build_query($query)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''),
        );
    }
}
