<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\FeatureAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantFeature
{
    public function handle(Request $request, Closure $next, string $featureCode): Response
    {
        $user = request()->attributes->get('restaurant_admin_access_owner')
            ?? Auth::guard('web')->user();
        if (! $user instanceof User || ! $user->restaurant) {
            return response()->json(['message' => 'A valid restaurant account is required.'], 403);
        }

        if (! app(FeatureAccessService::class)->allows($user->restaurant, $featureCode)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This feature is not included in the restaurant subscription plan.',
                    'code' => 'feature_unavailable',
                ], 403);
            }

            abort(403, 'This feature is not included in the restaurant subscription plan.');
        }

        return $next($request);
    }
}
