<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthenticatedRestaurantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        $sessionPasswordVersion = $request->session()->get('restaurant_user_password_version');

        if (
            $user instanceof User
            && $user->isActive()
            && $user->restaurant
            && $user->restaurant->isActive()
            && is_string($sessionPasswordVersion)
            && hash_equals(hash('sha256', $user->getAuthPassword()), $sessionPasswordVersion)
        ) {
            return redirect()->route('restaurant.dashboard');
        }

        if ($user) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
