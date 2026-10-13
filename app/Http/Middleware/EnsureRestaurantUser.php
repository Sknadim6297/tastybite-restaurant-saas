<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user instanceof User) {
            $user = User::query()->with('restaurant')->find($user->getKey());

            if ($user instanceof User) {
                $guard->setUser($user);
            }
        }

        $sessionPasswordVersion = $request->session()->get('restaurant_user_password_version');

        if (
            ! $user instanceof User
            || ! $user->isActive()
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
