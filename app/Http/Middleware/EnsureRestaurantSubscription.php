<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = request()->attributes->get('restaurant_admin_access_owner')
            ?? Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->restaurant) {
            return response()->json(['message' => 'A valid restaurant account is required.'], 403);
        }

        if ($user->restaurant->approval_status !== 'approved') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Restaurant operational access is unavailable until platform approval is complete.',
                    'code' => 'restaurant_approval_required',
                ], 403);
            }

            return redirect()->route('restaurant.dashboard')
                ->with('status', 'Operational access is unavailable until the platform approval process is complete.')
                ->with('toast_type', 'warning');
        }

        $now = now(config('app.timezone'))->utc();
        $subscription = $user->restaurant->subscriptions()
            ->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>=', $now)
            ->orderByDesc('id')
            ->first();

        if (! $subscription) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'A current subscription is required to access operational features.',
                    'code' => 'subscription_required',
                ], 403);
            }

            return redirect()->route('restaurant.subscription')
                ->with('status', 'A current subscription is required to access operational features.')
                ->with('toast_type', 'warning');
        }

        return $next($request);
    }
}
