<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantDashboardAccessService
{
    public const SESSION_RESTAURANT_ID = 'super_admin_restaurant_access_restaurant_id';

    private const SESSION_ADMIN_ID = 'super_admin_restaurant_access_admin_id';

    private const SESSION_STARTED_AT = 'super_admin_restaurant_access_started_at';

    private const SESSION_EXPIRES_AT = 'super_admin_restaurant_access_expires_at';

    private const ACCESS_DURATION_MINUTES = 60;

    public function start(Restaurant $restaurant, SuperAdmin $admin, Request $request): void
    {
        $this->assertEligible($restaurant);

        $previousRestaurantId = $request->session()->get(self::SESSION_RESTAURANT_ID);
        $this->clearSession($request);
        if (is_numeric($previousRestaurantId)) {
            $previousRestaurant = Restaurant::query()->find((int) $previousRestaurantId);
            if ($previousRestaurant) {
                $this->writeAudit($admin, $previousRestaurant, 'super_admin_restaurant_access_ended', [
                    'reason' => 'switched_restaurant',
                ]);
            }
        }

        $request->session()->regenerate(true);
        $startedAt = now();
        $expiresAt = $startedAt->copy()->addMinutes(self::ACCESS_DURATION_MINUTES);
        $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_access_started', [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
        $request->session()->put([
            self::SESSION_RESTAURANT_ID => $restaurant->id,
            self::SESSION_ADMIN_ID => $admin->id,
            self::SESSION_STARTED_AT => $startedAt->timestamp,
            self::SESSION_EXPIRES_AT => $expiresAt->timestamp,
        ]);
    }

    public function end(Request $request, SuperAdmin $admin): void
    {
        $restaurantId = $request->session()->get(self::SESSION_RESTAURANT_ID);
        $startedAt = $request->session()->get(self::SESSION_STARTED_AT);
        $this->clearSession($request);
        $request->session()->regenerate(true);

        if (is_numeric($restaurantId)) {
            $restaurant = Restaurant::query()->find((int) $restaurantId);
            if ($restaurant) {
                $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_access_ended', [
                    'reason' => 'returned_to_super_admin',
                    'started_at' => $startedAt,
                ]);
            }
        }
    }

    public function clearForLogout(Request $request, SuperAdmin $admin): void
    {
        if ($request->session()->has(self::SESSION_RESTAURANT_ID)) {
            $this->end($request, $admin);
        }
    }

    public function restaurantFor(Request $request): ?Restaurant
    {
        $restaurantId = $request->session()->get(self::SESSION_RESTAURANT_ID);
        $sessionAdminId = $request->session()->get(self::SESSION_ADMIN_ID);
        $expiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);
        $admin = Auth::guard('super_admin')->user();
        $passwordVersion = $request->session()->get('super_admin_password_version');

        if (
            ! is_numeric($restaurantId)
            || ! is_numeric($sessionAdminId)
            || ! is_numeric($expiresAt)
            || ! $admin instanceof SuperAdmin
            || ! $admin->is_active
            || (int) $sessionAdminId !== (int) $admin->id
            || (int) $expiresAt <= now()->timestamp
            || ! is_string($passwordVersion)
            || ! hash_equals(hash('sha256', $admin->getAuthPassword()), $passwordVersion)
        ) {
            return null;
        }

        $restaurant = Restaurant::query()->find((int) $restaurantId);

        return $restaurant && $this->isEligible($restaurant) ? $restaurant : null;
    }

    public function terminateInvalidSession(Request $request, ?SuperAdmin $admin): void
    {
        $restaurantId = $request->session()->get(self::SESSION_RESTAURANT_ID);
        $expiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);
        $expired = is_numeric($expiresAt) && (int) $expiresAt <= now()->timestamp;
        $this->clearSession($request);
        $request->session()->regenerate(true);

        if ($admin instanceof SuperAdmin && is_numeric($restaurantId)) {
            $restaurant = Restaurant::query()->find((int) $restaurantId);
            if ($restaurant) {
                $this->writeAudit(
                    $admin,
                    $restaurant,
                    $expired
                        ? 'super_admin_restaurant_access_expired'
                        : 'super_admin_restaurant_access_revoked',
                    ['reason' => $expired ? 'time_limit_reached' : 'authorization_or_tenant_invalid'],
                );
            }
        }
    }

    public function allowsRoute(string $routeName, string $method): bool
    {
        $allowed = [
            'restaurant.dashboard' => ['GET', 'HEAD'],
            'pos.index' => ['GET', 'HEAD'],
            'restaurant.menu.categories.index' => ['GET', 'HEAD'],
            'restaurant.menu.categories.create' => ['GET', 'HEAD'],
            'restaurant.menu.categories.store' => ['POST'],
            'restaurant.menu.categories.edit' => ['GET', 'HEAD'],
            'restaurant.menu.categories.update' => ['PUT'],
            'restaurant.menu.categories.status' => ['PATCH'],
            'restaurant.menu.products.index' => ['GET', 'HEAD'],
            'restaurant.menu.products.create' => ['GET', 'HEAD'],
            'restaurant.menu.products.store' => ['POST'],
            'restaurant.menu.products.edit' => ['GET', 'HEAD'],
            'restaurant.menu.products.update' => ['PUT'],
        ];

        return in_array($method, $allowed[$routeName] ?? [], true);
    }

    public function logAction(Restaurant $restaurant, SuperAdmin $admin, Request $request, int $status): void
    {
        $routeName = (string) $request->route()?->getName();
        $resourceId = $request->route('category') ?? $request->route('product');
        $action = match ($routeName) {
            'restaurant.dashboard' => 'dashboard_viewed',
            'pos.index' => 'pos_viewed',
            'restaurant.menu.categories.index' => 'categories_viewed',
            'restaurant.menu.categories.create' => 'category_form_viewed',
            'restaurant.menu.categories.store' => 'category_created',
            'restaurant.menu.categories.edit' => 'category_edit_viewed',
            'restaurant.menu.categories.update' => 'category_updated',
            'restaurant.menu.categories.status' => 'category_status_changed',
            'restaurant.menu.products.index' => 'products_viewed',
            'restaurant.menu.products.create' => 'product_form_viewed',
            'restaurant.menu.products.store' => 'product_created',
            'restaurant.menu.products.edit' => 'product_edit_viewed',
            'restaurant.menu.products.update' => 'product_updated',
            default => 'action',
        };

        $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_'.$action, [
            'route' => $routeName,
            'method' => $request->method(),
            'http_status' => $status,
            'resource_id' => is_numeric($resourceId) ? (int) $resourceId : null,
            'admin_access_started_at' => $request->session()->get(self::SESSION_STARTED_AT),
        ]);
    }

    private function assertEligible(Restaurant $restaurant): void
    {
        abort_unless($this->isEligible($restaurant), 403, 'This restaurant is not eligible for administrative access.');
    }

    private function isEligible(Restaurant $restaurant): bool
    {
        return ! str_starts_with($restaurant->slug, 'demo-tb-2026-')
            && $restaurant->status === 'active'
            && $restaurant->approval_status === 'approved'
            && $restaurant->owners()->where('status', 'active')->exists();
    }

    private function clearSession(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_RESTAURANT_ID,
            self::SESSION_ADMIN_ID,
            self::SESSION_STARTED_AT,
            self::SESSION_EXPIRES_AT,
        ]);
    }

    private function writeAudit(SuperAdmin $admin, Restaurant $restaurant, string $action, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $admin->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'restaurant',
            'target_id' => $restaurant->id,
            'restaurant_id' => $restaurant->id,
            'details' => [
                'restaurant_id' => $restaurant->id,
                ...$details,
            ],
        ]);
    }
}
