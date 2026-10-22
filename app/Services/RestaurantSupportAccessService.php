<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessRequestEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantSupportAccessService
{
    public const SESSION_REQUEST_ID = 'super_admin_support_access_request_id';

    public const SESSION_RESTAURANT_ID = 'super_admin_support_access_restaurant_id';

    public const SESSION_ADMIN_ACCESS_RESTAURANT_ID = 'super_admin_restaurant_access_restaurant_id';

    private const SESSION_ADMIN_ACCESS_ADMIN_ID = 'super_admin_restaurant_access_admin_id';

    private const SESSION_ADMIN_ACCESS_STARTED_AT = 'super_admin_restaurant_access_started_at';

    private const SESSION_ADMIN_ACCESS_EXPIRES_AT = 'super_admin_restaurant_access_expires_at';

    private const ADMIN_ACCESS_DURATION_MINUTES = 60;

    public function startDirect(Restaurant $restaurant, SuperAdmin $admin, Request $request): void
    {
        $this->assertRestaurantEligible($restaurant);
        if ($request->session()->has(self::SESSION_REQUEST_ID)) {
            $this->end($request, $admin);
        }
        $previousRestaurantId = $request->session()->get(self::SESSION_ADMIN_ACCESS_RESTAURANT_ID);
        $this->clearDirectSession($request);
        if (is_numeric($previousRestaurantId)) {
            $previousRestaurant = Restaurant::query()->find((int) $previousRestaurantId);
            if ($previousRestaurant) {
                $this->writeDirectAudit($admin, $previousRestaurant, 'super_admin_restaurant_access_ended', [
                    'reason' => 'switched_restaurant',
                ]);
            }
        }

        $request->session()->regenerate(true);
        $startedAt = now();
        $this->writeDirectAudit($admin, $restaurant, 'super_admin_restaurant_access_started', [
            'expires_at' => $startedAt->copy()->addMinutes(self::ADMIN_ACCESS_DURATION_MINUTES)->toIso8601String(),
        ]);
        $request->session()->put([
            self::SESSION_ADMIN_ACCESS_RESTAURANT_ID => $restaurant->id,
            self::SESSION_ADMIN_ACCESS_ADMIN_ID => $admin->id,
            self::SESSION_ADMIN_ACCESS_STARTED_AT => $startedAt->timestamp,
            self::SESSION_ADMIN_ACCESS_EXPIRES_AT => $startedAt->copy()
                ->addMinutes(self::ADMIN_ACCESS_DURATION_MINUTES)
                ->timestamp,
        ]);
    }

    public function endDirect(Request $request, SuperAdmin $admin): void
    {
        $restaurantId = $request->session()->get(self::SESSION_ADMIN_ACCESS_RESTAURANT_ID);
        $startedAt = $request->session()->get(self::SESSION_ADMIN_ACCESS_STARTED_AT);
        $this->clearDirectSession($request);
        $request->session()->regenerate(true);

        if (is_numeric($restaurantId)) {
            $restaurant = Restaurant::query()->find((int) $restaurantId);
            if ($restaurant) {
                $this->writeDirectAudit($admin, $restaurant, 'super_admin_restaurant_access_ended', [
                    'reason' => 'returned_to_super_admin',
                    'started_at' => $startedAt,
                ]);
            }
        }
    }

    public function clearDirectAccessForLogout(Request $request, SuperAdmin $admin): void
    {
        if ($request->session()->has(self::SESSION_ADMIN_ACCESS_RESTAURANT_ID)) {
            $this->endDirect($request, $admin);
        }
    }

    public function activeDirectRestaurantFor(Request $request): ?Restaurant
    {
        $restaurantId = $request->session()->get(self::SESSION_ADMIN_ACCESS_RESTAURANT_ID);
        $sessionAdminId = $request->session()->get(self::SESSION_ADMIN_ACCESS_ADMIN_ID);
        $expiresAt = $request->session()->get(self::SESSION_ADMIN_ACCESS_EXPIRES_AT);
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
        if (! $restaurant || ! $this->restaurantIsEligible($restaurant)) {
            return null;
        }

        return $restaurant;
    }

    public function terminateInvalidDirectSession(Request $request, ?SuperAdmin $admin): void
    {
        $restaurantId = $request->session()->get(self::SESSION_ADMIN_ACCESS_RESTAURANT_ID);
        $expiresAt = $request->session()->get(self::SESSION_ADMIN_ACCESS_EXPIRES_AT);
        $expired = is_numeric($expiresAt) && (int) $expiresAt <= now()->timestamp;
        $this->clearDirectSession($request);
        $request->session()->regenerate(true);
        if (is_numeric($restaurantId) && $admin instanceof SuperAdmin) {
            $restaurant = Restaurant::query()->find((int) $restaurantId);
            if ($restaurant) {
                $this->writeDirectAudit(
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

    public function allowsDirectRoute(string $routeName, string $method): bool
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

    public function logDirectAction(Restaurant $restaurant, SuperAdmin $admin, Request $request, int $status): void
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

        $this->writeDirectAudit($admin, $restaurant, 'super_admin_restaurant_'.$action, [
            'route' => $routeName,
            'method' => $request->method(),
            'http_status' => $status,
            'resource_id' => is_numeric($resourceId) ? (int) $resourceId : null,
            'access_started_at' => $request->session()->get(self::SESSION_ADMIN_ACCESS_STARTED_AT),
        ]);
    }

    public function start(SupportAccessRequest $supportRequest, SuperAdmin $admin, Request $request): SupportAccessRequest
    {
        $supportRequest = DB::transaction(function () use ($supportRequest, $admin): SupportAccessRequest {
            $locked = SupportAccessRequest::query()->lockForUpdate()->findOrFail($supportRequest->id);
            $this->assertEligible($locked, $admin);

            if ($locked->access_started_at !== null) {
                throw ValidationException::withMessages([
                    'support_access' => 'This approved access window has already been used and cannot be restarted.',
                ]);
            }

            $now = now();
            $locked->update([
                'access_started_at' => $now,
                'access_expires_at' => $now->copy()->addMinutes($locked->requested_duration_minutes),
            ]);
            $this->record($locked, $admin, 'access_started', 'Support access session started.');

            return $locked->fresh();
        });

        $request->session()->regenerate(true);
        $request->session()->put(self::SESSION_REQUEST_ID, $supportRequest->id);
        $request->session()->put(self::SESSION_RESTAURANT_ID, $supportRequest->restaurant_id);

        return $supportRequest;
    }

    public function end(Request $request, SuperAdmin $admin): void
    {
        $requestId = $request->session()->get(self::SESSION_REQUEST_ID);
        if (is_numeric($requestId)) {
            DB::transaction(function () use ($requestId, $admin): void {
                $supportRequest = SupportAccessRequest::query()->lockForUpdate()->find($requestId);
                if (
                    $supportRequest
                    && $supportRequest->requester_admin_id === $admin->id
                    && $supportRequest->access_started_at !== null
                    && $supportRequest->access_ended_at === null
                    && $supportRequest->access_revoked_at === null
                ) {
                    $supportRequest->update(['access_ended_at' => now()]);
                    $this->record($supportRequest, $admin, 'access_ended', 'Support access session ended by requester.');
                }
            });
        }

        $request->session()->forget([
            self::SESSION_REQUEST_ID,
            self::SESSION_RESTAURANT_ID,
        ]);
        $request->session()->regenerate(true);
    }

    public function revoke(SupportAccessRequest $supportRequest, SuperAdmin $admin, ?string $reason): void
    {
        DB::transaction(function () use ($supportRequest, $admin, $reason): void {
            $locked = SupportAccessRequest::query()->lockForUpdate()->findOrFail($supportRequest->id);
            if (
                $locked->status !== 'approved'
                || $locked->requested_scope !== SupportAccessRequest::RESTAURANT_DASHBOARD_MENU_MANAGE
                || $locked->requester_admin_id === $admin->id
                || $locked->access_started_at === null
                || $locked->access_ended_at !== null
            ) {
                throw ValidationException::withMessages([
                    'support_access' => 'Only a different Super Admin may revoke an active, approved support session.',
                ]);
            }
            if ($locked->access_revoked_at !== null) {
                throw ValidationException::withMessages(['support_access' => 'This support session has already been revoked.']);
            }

            $locked->update(['access_revoked_at' => now()]);
            $this->record($locked, $admin, 'access_revoked', $reason ?: 'Support access revoked by an active Super Admin.');
        });
    }

    public function activeRequestFor(Request $request): ?SupportAccessRequest
    {
        $requestId = $request->session()->get(self::SESSION_REQUEST_ID);
        $restaurantId = $request->session()->get(self::SESSION_RESTAURANT_ID);
        $admin = Auth::guard('super_admin')->user();
        $passwordVersion = $request->session()->get('super_admin_password_version');

        if (
            ! is_numeric($requestId)
            || ! is_numeric($restaurantId)
            || ! $admin instanceof SuperAdmin
            || ! $admin->is_active
            || ! is_string($passwordVersion)
            || ! hash_equals(hash('sha256', $admin->getAuthPassword()), $passwordVersion)
        ) {
            return null;
        }

        $supportRequest = SupportAccessRequest::query()
            ->with(['restaurant', 'reviewer'])
            ->whereKey($requestId)
            ->where('restaurant_id', $restaurantId)
            ->where('requester_admin_id', $admin->id)
            ->first();

        if (
            ! $supportRequest
            || ! $supportRequest->hasActiveDashboardAccess()
            || ! $supportRequest->reviewer?->is_active
            || ! $supportRequest->restaurant
            || $supportRequest->restaurant->status !== 'active'
            || $supportRequest->restaurant->approval_status !== 'approved'
            || ! $supportRequest->restaurant->owners()->where('status', 'active')->exists()
        ) {
            return null;
        }

        return $supportRequest;
    }

    public function terminateInvalidSession(Request $request): void
    {
        $requestId = $request->session()->get(self::SESSION_REQUEST_ID);
        $admin = Auth::guard('super_admin')->user();
        if (! is_numeric($requestId) || ! $admin instanceof SuperAdmin) {
            return;
        }

        DB::transaction(function () use ($requestId, $admin): void {
            $supportRequest = SupportAccessRequest::query()->lockForUpdate()->find($requestId);
            if (! $supportRequest || $supportRequest->requester_admin_id !== $admin->id
                || $supportRequest->access_started_at === null
                || $supportRequest->access_ended_at !== null
                || $supportRequest->access_revoked_at !== null) {
                return;
            }

            if ($supportRequest->access_expires_at !== null && $supportRequest->access_expires_at->isPast()) {
                $supportRequest->update(['access_ended_at' => now()]);
                $this->record($supportRequest, $admin, 'access_expired', 'Support access ended because its approved time limit expired.');

                return;
            }

            $supportRequest->update(['access_revoked_at' => now()]);
            $this->record($supportRequest, $admin, 'access_revoked', 'Support access was revoked because its session authorization context became invalid.');
        });
    }

    public function allowsRoute(string $routeName, string $method): bool
    {
        $allowed = [
            'restaurant.dashboard' => ['GET', 'HEAD'],
            'restaurant.menu.categories.index' => ['GET', 'HEAD'],
            'restaurant.menu.categories.create' => ['GET', 'HEAD'],
            'restaurant.menu.categories.store' => ['POST'],
            'restaurant.menu.categories.edit' => ['GET', 'HEAD'],
            'restaurant.menu.categories.update' => ['PUT'],
            'restaurant.menu.products.index' => ['GET', 'HEAD'],
            'restaurant.menu.products.create' => ['GET', 'HEAD'],
            'restaurant.menu.products.store' => ['POST'],
            'restaurant.menu.products.edit' => ['GET', 'HEAD'],
            'restaurant.menu.products.update' => ['PUT'],
        ];

        return in_array($method, $allowed[$routeName] ?? [], true);
    }

    private function assertRestaurantEligible(Restaurant $restaurant): void
    {
        abort_unless($this->restaurantIsEligible($restaurant), 403, 'This restaurant is not eligible for administrative access.');
    }

    private function restaurantIsEligible(Restaurant $restaurant): bool
    {
        return $restaurant->status === 'active'
            && $restaurant->approval_status === 'approved'
            && $restaurant->owners()->where('status', 'active')->exists();
    }

    private function clearDirectSession(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_ADMIN_ACCESS_RESTAURANT_ID,
            self::SESSION_ADMIN_ACCESS_ADMIN_ID,
            self::SESSION_ADMIN_ACCESS_STARTED_AT,
            self::SESSION_ADMIN_ACCESS_EXPIRES_AT,
        ]);
    }

    private function writeDirectAudit(
        SuperAdmin $admin,
        Restaurant $restaurant,
        string $action,
        array $details,
    ): void {
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

    public function logAction(SupportAccessRequest $supportRequest, SuperAdmin $admin, Request $request, int $status): void
    {
        $routeName = (string) $request->route()?->getName();
        $action = match ($routeName) {
            'restaurant.dashboard' => 'dashboard_viewed',
            'restaurant.menu.categories.index' => 'categories_viewed',
            'restaurant.menu.categories.create' => 'category_form_viewed',
            'restaurant.menu.categories.store' => 'category_created',
            'restaurant.menu.categories.edit' => 'category_edit_viewed',
            'restaurant.menu.categories.update' => 'category_updated',
            'restaurant.menu.products.index' => 'products_viewed',
            'restaurant.menu.products.create' => 'product_form_viewed',
            'restaurant.menu.products.store' => 'product_created',
            'restaurant.menu.products.edit' => 'product_edit_viewed',
            'restaurant.menu.products.update' => 'product_updated',
            default => 'support_action',
        };
        $resourceId = $request->route('category') ?? $request->route('product');
        $details = [
            'restaurant_id' => $supportRequest->restaurant_id,
            'support_access_request_id' => $supportRequest->id,
            'route' => $routeName,
            'method' => $request->method(),
            'http_status' => $status,
            'resource_id' => is_numeric($resourceId) ? (int) $resourceId : null,
        ];

        SupportAccessRequestEvent::create([
            'support_access_request_id' => $supportRequest->id,
            'actor_admin_id' => $admin->id,
            'action' => $action,
            'previous_status' => 'approved',
            'new_status' => 'approved',
            'reason' => $routeName.' '.$request->method().' returned HTTP '.$status.'.',
            'created_at' => now(),
        ]);
        RestaurantAuditLog::create([
            'actor_id' => $admin->id,
            'actor_type' => 'super_admin',
            'action' => 'support_access_'.$action,
            'target_type' => 'restaurant',
            'target_id' => $supportRequest->restaurant_id,
            'restaurant_id' => $supportRequest->restaurant_id,
            'details' => $details,
        ]);
    }

    private function assertEligible(SupportAccessRequest $supportRequest, SuperAdmin $admin): void
    {
        $supportRequest->loadMissing(['restaurant', 'reviewer']);
        if (
            $supportRequest->status !== 'approved'
            || $supportRequest->requested_scope !== SupportAccessRequest::RESTAURANT_DASHBOARD_MENU_MANAGE
            || $supportRequest->requester_admin_id !== $admin->id
            || $supportRequest->reviewer_admin_id === null
            || $supportRequest->reviewer_admin_id === $admin->id
            || ! $supportRequest->reviewer?->is_active
            || ! $supportRequest->restaurant
            || $supportRequest->restaurant->status !== 'active'
            || $supportRequest->restaurant->approval_status !== 'approved'
            || ! $supportRequest->restaurant->owners()->where('status', 'active')->exists()
            || $supportRequest->access_started_at !== null
            || $supportRequest->access_ended_at !== null
            || $supportRequest->access_revoked_at !== null
        ) {
            throw ValidationException::withMessages([
                'support_access' => 'This request is not eligible for dashboard access. Verify its approval, scope, expiry state, and restaurant eligibility.',
            ]);
        }
    }

    private function record(SupportAccessRequest $supportRequest, SuperAdmin $admin, string $action, string $reason): void
    {
        SupportAccessRequestEvent::create([
            'support_access_request_id' => $supportRequest->id,
            'actor_admin_id' => $admin->id,
            'action' => $action,
            'previous_status' => $supportRequest->status,
            'new_status' => $supportRequest->status,
            'reason' => $reason,
            'created_at' => now(),
        ]);
        RestaurantAuditLog::create([
            'actor_id' => $admin->id,
            'actor_type' => 'super_admin',
            'action' => 'support_access_'.$action,
            'target_type' => 'support_access_request',
            'target_id' => $supportRequest->id,
            'restaurant_id' => $supportRequest->restaurant_id,
            'details' => [
                'restaurant_id' => $supportRequest->restaurant_id,
                'support_access_request_id' => $supportRequest->id,
                'reason' => $reason,
                'access_expires_at' => $supportRequest->access_expires_at?->toIso8601String(),
            ],
        ]);
    }
}
