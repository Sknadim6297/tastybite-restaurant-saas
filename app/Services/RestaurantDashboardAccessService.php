<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RestaurantDashboardAccessService
{
    public const ACCESS_TOKEN_PARAMETER = 'admin_access';

    public const SESSION_CONTEXTS = 'super_admin_restaurant_access_contexts';

    private const ACCESS_DURATION_MINUTES = 60;

    public function start(Restaurant $restaurant, SuperAdmin $admin, Request $request): string
    {
        $this->assertEligible($restaurant);

        $token = Str::random(64);
        $startedAt = now();
        $expiresAt = $startedAt->copy()->addMinutes(self::ACCESS_DURATION_MINUTES);
        $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_access_started', [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);
        $contexts[$token] = [
            'restaurant_id' => $restaurant->id,
            'admin_id' => $admin->id,
            'started_at' => $startedAt->timestamp,
            'expires_at' => $expiresAt->timestamp,
        ];
        $request->session()->put(self::SESSION_CONTEXTS, $contexts);

        return $token;
    }

    public function end(Request $request, SuperAdmin $admin): void
    {
        $token = $request->query(self::ACCESS_TOKEN_PARAMETER);
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);
        $context = is_string($token) ? ($contexts[$token] ?? null) : null;
        abort_unless(
            is_array($context) && (int) ($context['admin_id'] ?? 0) === (int) $admin->id,
            403,
            'This administrative restaurant session is invalid.',
        );
        unset($contexts[$token]);
        $this->storeContexts($request, $contexts);

        $restaurant = Restaurant::query()->find((int) $context['restaurant_id']);
        if ($restaurant) {
            $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_access_ended', [
                'reason' => 'returned_to_super_admin',
                'started_at' => $context['started_at'] ?? null,
            ]);
        }
    }

    public function clearForLogout(Request $request, SuperAdmin $admin): void
    {
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);
        foreach ($contexts as $context) {
            if (! is_array($context) || (int) ($context['admin_id'] ?? 0) !== (int) $admin->id) {
                continue;
            }
            $restaurant = Restaurant::query()->find((int) ($context['restaurant_id'] ?? 0));
            if ($restaurant) {
                $this->writeAudit($admin, $restaurant, 'super_admin_restaurant_access_ended', [
                    'reason' => 'super_admin_logout',
                    'started_at' => $context['started_at'] ?? null,
                ]);
            }
        }
        $request->session()->forget(self::SESSION_CONTEXTS);
    }

    public function restaurantFor(Request $request): ?Restaurant
    {
        $token = $request->query(self::ACCESS_TOKEN_PARAMETER);
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);
        $context = is_string($token) ? ($contexts[$token] ?? null) : null;
        $admin = Auth::guard('super_admin')->user();
        $passwordVersion = $request->session()->get('super_admin_password_version');

        if (
            ! is_array($context)
            || ! is_numeric($context['restaurant_id'] ?? null)
            || ! is_numeric($context['admin_id'] ?? null)
            || ! is_numeric($context['expires_at'] ?? null)
            || ! $admin instanceof SuperAdmin
            || ! $admin->is_active
            || (int) $context['admin_id'] !== (int) $admin->id
            || (int) $context['expires_at'] <= now()->timestamp
            || ! is_string($passwordVersion)
            || ! hash_equals(hash('sha256', $admin->getAuthPassword()), $passwordVersion)
        ) {
            return null;
        }

        $restaurant = Restaurant::query()->find((int) $context['restaurant_id']);

        return $restaurant && $this->isEligible($restaurant) ? $restaurant : null;
    }

    public function terminateInvalidSession(Request $request, ?SuperAdmin $admin): void
    {
        $token = $request->query(self::ACCESS_TOKEN_PARAMETER);
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);
        $context = is_string($token) ? ($contexts[$token] ?? null) : null;
        if (is_string($token)) {
            unset($contexts[$token]);
            $this->storeContexts($request, $contexts);
        }
        $expired = is_array($context)
            && is_numeric($context['expires_at'] ?? null)
            && (int) $context['expires_at'] <= now()->timestamp;

        if ($admin instanceof SuperAdmin && is_numeric($context['restaurant_id'] ?? null)) {
            $restaurant = Restaurant::query()->find((int) $context['restaurant_id']);
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
            'pos.orders.index' => ['GET', 'HEAD'],
            'restaurant.orders.overview' => ['GET', 'HEAD'],
            'restaurant.orders.all' => ['GET', 'HEAD'],
            'restaurant.orders.dine-in' => ['GET', 'HEAD'],
            'restaurant.orders.fulfillment' => ['GET', 'HEAD'],
            'restaurant.orders.billing' => ['GET', 'HEAD'],
            'restaurant.orders.reports' => ['GET', 'HEAD'],
            'restaurant.orders.reports.export' => ['GET', 'HEAD'],
            'restaurant.orders.show' => ['GET', 'HEAD'],
            'restaurant.orders.receipt' => ['GET', 'HEAD'],
            'restaurant.subscription' => ['GET', 'HEAD'],
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

        if (in_array($method, $allowed[$routeName] ?? [], true)) {
            return true;
        }

        if (str_starts_with($routeName, 'restaurant.tables.')) {
            return in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true);
        }

        return in_array($method, ['GET', 'HEAD'], true)
            && (
                str_starts_with($routeName, 'restaurant.inventory.')
                || str_starts_with($routeName, 'restaurant.vendors.')
                || str_starts_with($routeName, 'restaurant.support.')
                || str_starts_with($routeName, 'restaurant.announcements.')
                || $routeName === 'restaurant.subscription.invoices.pdf'
            );
    }

    public function logAction(Restaurant $restaurant, SuperAdmin $admin, Request $request, int $status): void
    {
        $routeName = (string) $request->route()?->getName();
        $resourceId = $request->route('category') ?? $request->route('product') ?? $request->route('order')
            ?? $request->route('table') ?? $request->route('floor') ?? $request->route('section')
            ?? $request->route('reservation');
        $action = match ($routeName) {
            'restaurant.dashboard' => 'dashboard_viewed',
            'pos.index' => 'pos_viewed',
            'restaurant.orders.overview' => 'orders_overview_viewed',
            'restaurant.orders.all' => 'orders_list_viewed',
            'restaurant.orders.dine-in' => 'dine_in_orders_viewed',
            'restaurant.orders.fulfillment' => 'fulfillment_orders_viewed',
            'restaurant.orders.billing' => 'order_billing_viewed',
            'restaurant.orders.reports' => 'order_reports_viewed',
            'restaurant.orders.reports.export' => 'order_report_exported',
            'restaurant.orders.show' => 'order_details_viewed',
            'restaurant.orders.receipt' => 'order_receipt_viewed',
            'restaurant.tables.overview' => 'tables_overview_viewed',
            'restaurant.tables.index' => 'tables_list_viewed',
            'restaurant.tables.floors' => 'table_floors_viewed',
            'restaurant.tables.reservations' => 'table_reservations_viewed',
            'restaurant.tables.operations' => 'table_operations_viewed',
            'restaurant.tables.reports' => 'table_reports_viewed',
            'restaurant.tables.reports.export' => 'table_report_exported',
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
            'admin_access_started_at' => $this->currentContext($request)['started_at'] ?? null,
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

    /**
     * @return array<string, mixed>
     */
    private function currentContext(Request $request): array
    {
        $token = $request->query(self::ACCESS_TOKEN_PARAMETER);
        $contexts = $request->session()->get(self::SESSION_CONTEXTS, []);

        return is_string($token) && is_array($contexts[$token] ?? null)
            ? $contexts[$token]
            : [];
    }

    /**
     * @param  array<string, array<string, int>>  $contexts
     */
    private function storeContexts(Request $request, array $contexts): void
    {
        if ($contexts === []) {
            $request->session()->forget(self::SESSION_CONTEXTS);

            return;
        }

        $request->session()->put(self::SESSION_CONTEXTS, $contexts);
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
