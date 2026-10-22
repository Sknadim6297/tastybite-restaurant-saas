<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSubscription;
use App\Services\RestaurantAccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use RuntimeException;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = app(RestaurantAccessContext::class)->owner();
        $restaurant = $user->restaurant;
        $now = CarbonImmutable::now(config('app.timezone'));
        $subscription = $restaurant->currentSubscriptionAt($now);
        $subscriptionStatus = $subscription?->effectiveStatus($now);
        $daysRemaining = 0;

        if ($subscription instanceof RestaurantSubscription && $subscription->expires_at->gte($now)) {
            $expiryLocal = $subscription->expires_at->timezone(config('app.timezone'));
            $daysRemaining = max(0, (int) $now->startOfDay()->diffInDays($expiryLocal->startOfDay(), false));
        }

        $approvalStatus = $restaurant->approval_status;
        $approvalNotice = $approvalStatus !== 'approved'
            ? $restaurant->approvalHistory()->first()
            : null;

        return view('restaurant.dashboard', [
            'user' => $user,
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'subscriptionStatus' => $subscriptionStatus,
            'daysRemaining' => $daysRemaining,
            'approvalStatus' => $approvalStatus,
            'approvalNotice' => $approvalNotice,
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

    public function updateBranding(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        abort_unless($user->isOwner() && $user->restaurant, 403);

        $validated = $request->validate([
            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=64,min_height=64,max_width=2048,max_height=2048',
            ],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);
        $restaurant = $user->restaurant;
        $image = $validated['logo'] ?? null;
        $removeLogo = (bool) ($validated['remove_logo'] ?? false);
        $oldPath = $restaurant->logo_path;
        $newPath = $image instanceof UploadedFile
            ? $image->store('restaurant-branding/'.$restaurant->id, 'public')
            : null;

        if ($image instanceof UploadedFile && (! \is_string($newPath) || $newPath === '')) {
            throw new RuntimeException('The restaurant logo could not be stored.');
        }

        if ($newPath !== null || $removeLogo) {
            $restaurant->update(['logo_path' => $newPath]);

            if ($oldPath) {
                $this->deleteRestaurantLogo($oldPath, $restaurant->id);
            }
        }

        return redirect()->route('restaurant.profile')
            ->with('status', $newPath !== null || $removeLogo
                ? 'Restaurant branding updated successfully.'
                : 'No branding changes were made.');
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

    private function deleteRestaurantLogo(string $path, int $restaurantId): void
    {
        $directory = 'restaurant-branding/'.$restaurantId;
        $filename = basename($path);

        if (
            dirname($path) !== $directory
            || $filename === ''
            || ! preg_match('/\A[A-Za-z0-9._-]+\z/', $filename)
        ) {
            return;
        }

        $disk = Storage::disk('public');
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('The previous restaurant logo could not be removed from storage.');
        }
    }
}
