<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Services\RestaurantSupportAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('super_admin');
        $superAdmin = $guard->user();
        $sessionPasswordVersion = $request->session()->get('super_admin_password_version');

        if (
            ! $superAdmin instanceof SuperAdmin
            || ! $superAdmin->is_active
            || ! is_string($sessionPasswordVersion)
            || ! hash_equals(hash('sha256', $superAdmin->getAuthPassword()), $sessionPasswordVersion)
        ) {
            if ($request->session()->has(RestaurantSupportAccessService::SESSION_ADMIN_ACCESS_RESTAURANT_ID)) {
                app(RestaurantSupportAccessService::class)->terminateInvalidDirectSession(
                    $request,
                    $superAdmin instanceof SuperAdmin ? $superAdmin : null,
                );
            }

            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('super-admin.login')->withErrors([
                'email' => 'Your session has expired. Please sign in again.',
            ]);
        }

        return $next($request);
    }
}
