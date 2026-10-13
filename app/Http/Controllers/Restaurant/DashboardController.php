<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::guard('web')->user();
        $restaurant = $user->restaurant;
        $now = CarbonImmutable::now(config('app.timezone'));
        $subscription = $restaurant->currentSubscriptionAt($now);
        $subscriptionStatus = $subscription?->effectiveStatus($now);
        $daysRemaining = 0;

        if ($subscription instanceof RestaurantSubscription && $subscription->expires_at->gte($now)) {
            $expiryLocal = $subscription->expires_at->timezone(config('app.timezone'));
            $daysRemaining = max(0, (int) $now->startOfDay()->diffInDays($expiryLocal->startOfDay(), false));
        }

        return view('restaurant.dashboard', [
            'user' => $user,
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'subscriptionStatus' => $subscriptionStatus,
            'daysRemaining' => $daysRemaining,
            'approvalNotice' => $restaurant->approvalHistory()->first(),
        ]);
    }

    public function profile(): View
    {
        $user = Auth::guard('web')->user();

        return view('restaurant.profile', [
            'user' => $user,
            'restaurant' => $user->restaurant,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('restaurant.profile')
                ->withErrors($validator)
                ->withInput();
        }

        $user->update([
            'name' => $request->input('name'),
        ]);

        return redirect()->route('restaurant.profile')
            ->with('status', 'Profile details updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if ($validator->fails()) {
            return redirect()->route('restaurant.profile')
                ->withErrors($validator, 'passwordUpdate');
        }

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return redirect()->route('restaurant.profile')
                ->withErrors(['current_password' => 'The provided current password does not match our records.'], 'passwordUpdate');
        }

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
        ])->save();

        // Update session password version
        $request->session()->put(
            'restaurant_user_password_version',
            hash('sha256', $user->getAuthPassword())
        );

        return redirect()->route('restaurant.profile')
            ->with('status', 'Password updated successfully.');
    }
}
