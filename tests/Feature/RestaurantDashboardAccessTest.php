<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\RestaurantDashboardAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RestaurantDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    private User $otherOwner;

    private SuperAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = $this->createRestaurant('Access House', 'access-house');
        $this->otherRestaurant = $this->createRestaurant('Other House', 'other-house');
        $this->owner = $this->createOwner($this->restaurant, 'owner@access-house.test');
        $this->otherOwner = $this->createOwner($this->otherRestaurant, 'owner@other-house.test');
        $this->admin = $this->createAdmin('admin@access.test');
        $this->enablePosFeature($this->restaurant);
        $this->enablePosFeature($this->otherRestaurant);
    }

    public function test_super_admin_enters_directly_without_a_support_request_and_uses_the_existing_dashboard_menu_and_pos(): void
    {
        $this->signInSuperAdmin();
        Auth::guard('web')->login($this->owner);
        $this->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);

        $this->get(route('super-admin.restaurants.index'))
            ->assertOk()
            ->assertSee(route('super-admin.restaurants.dashboard', $this->restaurant))
            ->assertSee('target="_blank"', false);
        $this->get(route('super-admin.restaurants.show', $this->restaurant))
            ->assertOk()
            ->assertSee(route('super-admin.restaurants.dashboard', $this->restaurant))
            ->assertSee('target="_blank"', false);

        $token = $this->enterRestaurant($this->restaurant);

        $this->assertTrue(Auth::guard('super_admin')->check());
        $this->assertSame($this->admin->id, Auth::guard('super_admin')->id());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
        $this->assertDatabaseCount('support_access_requests', 0);

        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $token))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Access House')
            ->assertSee('Return to Super Admin')
            ->assertSee('Good to see you, Super Admin.');
        $dashboard = $this->get($this->restaurantAccessUrl('restaurant.dashboard', $token))
            ->assertOk()
            ->assertSee('data-access-token="'.$token.'"', false)
            ->assertSee('restaurant-admin-access.js');
        $dashboard->assertSee('Super Admin Access — Viewing: Access House')
            ->assertSee('Return to Super Admin')
            ->assertSee('Good to see you, Super Admin.');
        $this->get($this->restaurantAccessUrl('restaurant.inventory.overview', $token))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Access House')
            ->assertSee('Inventory Management')
            ->assertSee('Vendor Management');
        $this->get($this->restaurantAccessUrl('restaurant.vendors.overview', $token))
            ->assertOk()
            ->assertSee('Vendor Overview')
            ->assertSee('Super Admin Access — Viewing: Access House');

        $this->post($this->restaurantAccessUrl('restaurant.menu.categories.store', $token), [
            'name' => 'Admin Created',
            'description' => '',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect($this->restaurantAccessUrl('restaurant.menu.categories.index', $token));
        $category = RestaurantMenuCategory::query()->where('name', 'Admin Created')->firstOrFail();

        $this->post($this->restaurantAccessUrl('restaurant.menu.products.store', $token), [
            'name' => 'Admin Product',
            'category_id' => $category->id,
            'price' => '49.00',
            'food_type' => 'veg',
            'is_available' => '1',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect($this->restaurantAccessUrl('restaurant.menu.products.index', $token));
        $product = RestaurantMenuItem::query()->where('name', 'Admin Product')->firstOrFail();

        $this->get($this->restaurantAccessUrl('pos.index', $token))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Access House')
            ->assertSee('Admin Product');

        $this->assertSame($this->restaurant->id, $product->restaurant_id);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'actor_type' => 'super_admin',
            'action' => 'super_admin_restaurant_access_started',
            'restaurant_id' => $this->restaurant->id,
        ]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'actor_type' => 'super_admin',
            'action' => 'super_admin_restaurant_product_created',
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->delete($this->restaurantAccessUrl('restaurant.menu.products.destroy', $token, ['product' => $product]))->assertForbidden();
        $this->post($this->restaurantAccessUrl('restaurant.support.store', $token))->assertForbidden();
    }

    public function test_demo_fixture_tenants_cannot_be_opened_in_either_panel(): void
    {
        $demoRestaurant = $this->createRestaurant('Fixture Restaurant', 'demo-tb-2026-fixture');
        $demoOwner = $this->createOwner($demoRestaurant, 'fixture-owner@example.test');

        $this->signInSuperAdmin();
        $this->post(route('super-admin.restaurants.dashboard', $demoRestaurant))->assertForbidden();
        Auth::guard('super_admin')->logout();

        $this->actingAs($demoOwner, 'web');
        $this->withSession([
            'restaurant_user_password_version' => hash('sha256', $demoOwner->getAuthPassword()),
        ]);
        $this->get(route('restaurant.dashboard'))->assertRedirect(route('restaurant.login'));
    }

    public function test_super_admin_can_switch_restaurants_and_return_without_logging_out_or_changing_owner_identity(): void
    {
        $this->signInSuperAdmin();
        Auth::guard('web')->login($this->owner);
        $this->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);
        $firstToken = $this->enterRestaurant($this->restaurant);
        $secondToken = $this->enterRestaurant($this->otherRestaurant);

        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $secondToken))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Other House')
            ->assertSee('Good to see you, Super Admin.')
            ->assertDontSee('Access Owner');

        $this->post(route('super-admin.restaurants.dashboard.return', [
            RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER => $firstToken,
        ]))
            ->assertRedirect(route('super-admin.restaurants.index'));

        $this->assertTrue(Auth::guard('super_admin')->check());
        $this->assertSame($this->admin->id, Auth::guard('super_admin')->id());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'super_admin_restaurant_access_ended',
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertDontSee('Super Admin Access — Viewing:');
        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $secondToken))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Other House');
        $this->assertNotSame($firstToken, $secondToken);
    }

    public function test_owner_cannot_enter_admin_access_and_admin_access_cannot_cross_tenant_record_urls(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))
            ->assertRedirect(route('super-admin.login'));

        $this->signInSuperAdmin();
        $token = $this->enterRestaurant($this->restaurant);
        $otherProduct = $this->otherRestaurant->menuItems()->create([
            'name' => 'Private Other Product',
            'price' => '5.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->get($this->restaurantAccessUrl('restaurant.menu.products.edit', $token, ['product' => $otherProduct]))->assertNotFound();
        $this->get($this->restaurantAccessUrl('restaurant.profile', $token))->assertForbidden();
        $this->assertSame(
            $this->restaurant->id,
            session(RestaurantDashboardAccessService::SESSION_CONTEXTS)[$token]['restaurant_id'],
        );
    }

    public function test_access_expires_and_ineligible_restaurants_cannot_be_entered(): void
    {
        $this->signInSuperAdmin();
        $token = $this->enterRestaurant($this->restaurant);

        Carbon::setTestNow(now()->addMinutes(61));
        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $token))
            ->assertRedirect(route('super-admin.restaurants.index'));
        Carbon::setTestNow();

        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'super_admin_restaurant_access_expired',
            'restaurant_id' => $this->restaurant->id,
        ]);

        $this->restaurant->update(['status' => 'suspended']);
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))->assertForbidden();
    }

    public function test_super_admin_logout_ends_and_audits_direct_access(): void
    {
        $this->signInSuperAdmin();
        $this->enterRestaurant($this->restaurant);

        $this->post(route('super-admin.logout'))
            ->assertRedirect(route('super-admin.login'));

        $this->assertFalse(Auth::guard('super_admin')->check());
        $this->assertFalse(session()->has(RestaurantDashboardAccessService::SESSION_CONTEXTS));
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'super_admin_restaurant_access_ended',
            'restaurant_id' => $this->restaurant->id,
        ]);
    }

    public function test_normal_owner_dashboard_does_not_show_the_super_admin_banner(): void
    {
        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertDontSee('Super Admin Access — Viewing:')
            ->assertDontSee('Return to Super Admin');
    }

    public function test_super_admin_context_can_render_all_existing_restaurant_modules_without_cross_tab_leakage(): void
    {
        $this->signInSuperAdmin();
        $firstToken = $this->enterRestaurant($this->restaurant);
        $secondToken = $this->enterRestaurant($this->otherRestaurant);

        $pages = [
            'restaurant.dashboard',
            'pos.index',
            'pos.orders.index',
            'restaurant.menu.categories.index',
            'restaurant.menu.products.index',
            'restaurant.inventory.overview',
            'restaurant.inventory.items.index',
            'restaurant.inventory.categories.index',
            'restaurant.inventory.units.index',
            'restaurant.inventory.locations.index',
            'restaurant.inventory.stock.create',
            'restaurant.inventory.counts.index',
            'restaurant.inventory.wastage.index',
            'restaurant.inventory.ledger',
            'restaurant.inventory.batches',
            'restaurant.inventory.recipes.index',
            'restaurant.inventory.vendor-mappings.index',
            'restaurant.inventory.reports',
            'restaurant.inventory.settings',
            'restaurant.vendors.overview',
            'restaurant.vendors.index',
            'restaurant.vendors.purchase-orders.index',
            'restaurant.vendors.goods-received.index',
            'restaurant.subscription',
            'restaurant.support.index',
            'restaurant.announcements.index',
        ];

        foreach ($pages as $routeName) {
            $parameters = $routeName === 'restaurant.inventory.stock.create'
                ? ['kind' => 'opening']
                : [];
            $response = $this->get($this->restaurantAccessUrl($routeName, $firstToken, $parameters));
            $this->assertSame(200, $response->getStatusCode(), "Restaurant direct access failed for {$routeName}.");
        }

        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $secondToken))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Other House');
        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $firstToken))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Access House');

        $this->post(route('super-admin.restaurants.dashboard.return', [
            RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER => $firstToken,
        ]))->assertRedirect(route('super-admin.restaurants.index'));

        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $secondToken))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Other House');
        $this->get($this->restaurantAccessUrl('restaurant.dashboard', $firstToken))
            ->assertRedirect(route('super-admin.restaurants.index'));
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
    }

    private function createOwner(Restaurant $restaurant, string $email): User
    {
        $name = $restaurant->id === $this->restaurant?->id ? 'Access Owner' : 'Other Owner';

        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'email' => $email,
            'password' => 'StrongOwnerPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function createAdmin(string $email): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Platform Administrator',
            'email' => $email,
            'password' => 'StrongAdminPass123!',
            'is_active' => true,
        ]);
    }

    private function signInSuperAdmin(): void
    {
        $this->actingAs($this->admin, 'super_admin')->withSession([
            'super_admin_password_version' => hash('sha256', $this->admin->getAuthPassword()),
        ]);
    }

    private function enterRestaurant(Restaurant $restaurant): string
    {
        $response = $this->post(route('super-admin.restaurants.dashboard', $restaurant));
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $query[RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER] ?? null;
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }

    private function restaurantAccessUrl(string $routeName, string $token, array $parameters = []): string
    {
        return route($routeName, [
            ...$parameters,
            RestaurantDashboardAccessService::ACCESS_TOKEN_PARAMETER => $token,
        ]);
    }

    private function enablePosFeature(Restaurant $restaurant): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Direct Access Test Plan '.$restaurant->id,
            'code' => 'DIRECT-ACCESS-PLAN-'.$restaurant->id,
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $posFeature = SaasFeature::query()->where('code', 'pos')->firstOrFail();
        $plan->features()->attach($posFeature->id, ['is_enabled' => true, 'limit_value' => null]);

        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }
}
