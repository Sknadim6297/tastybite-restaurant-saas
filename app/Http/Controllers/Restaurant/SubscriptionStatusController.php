<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SubscriptionStatusController extends Controller
{
    public function show(): View
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User, 403);

        $restaurant = $user->restaurant;
        $now = CarbonImmutable::now(config('app.timezone'));
        $subscription = $restaurant->currentSubscriptionAt($now);
        $status = $subscription?->effectiveStatus($now);
        $daysRemaining = 0;

        if ($subscription instanceof RestaurantSubscription && $subscription->expires_at->gte($now)) {
            $expiryLocal = $subscription->expires_at->timezone(config('app.timezone'));
            $daysRemaining = max(0, (int) $now->startOfDay()->diffInDays($expiryLocal->startOfDay(), false));
        }

        return view('restaurant.subscription', [
            'user' => $user,
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'subscriptionStatus' => $status,
            'daysRemaining' => $daysRemaining,
        ]);
    }
}
