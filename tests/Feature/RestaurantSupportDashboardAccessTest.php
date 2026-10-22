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
use App\Services\RestaurantSupportAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RestaurantSupportDashboardAccessTest extends TestCase
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
            ->assertSee(route('super-admin.restaurants.dashboard', $this->restaurant));
        $this->get(route('super-admin.restaurants.show', $this->restaurant))
            ->assertOk()
            ->assertSee(route('super-admin.restaurants.dashboard', $this->restaurant));

        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))
            ->assertRedirect(route('restaurant.dashboard'));

        $this->assertTrue(Auth::guard('super_admin')->check());
        $this->assertSame($this->admin->id, Auth::guard('super_admin')->id());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
        $this->assertDatabaseCount('support_access_requests', 0);

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Access House')
            ->assertSee('Return to Super Admin')
            ->assertSee('Good to see you, Super Admin.');

        $this->post(route('restaurant.menu.categories.store'), [
            'name' => 'Admin Created',
            'description' => '',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect(route('restaurant.menu.categories.index'));
        $category = RestaurantMenuCategory::query()->where('name', 'Admin Created')->firstOrFail();

        $this->post(route('restaurant.menu.products.store'), [
            'name' => 'Admin Product',
            'category_id' => $category->id,
            'price' => '49.00',
            'food_type' => 'veg',
            'is_available' => '1',
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertRedirect(route('restaurant.menu.products.index'));
        $product = RestaurantMenuItem::query()->where('name', 'Admin Product')->firstOrFail();

        $this->get(route('pos.index'))
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

        $this->delete(route('restaurant.menu.products.destroy', $product))->assertForbidden();
        $this->post(route('restaurant.support.store'))->assertForbidden();
    }

    public function test_super_admin_can_switch_restaurants_and_return_without_logging_out_or_changing_owner_identity(): void
    {
        $this->signInSuperAdmin();
        Auth::guard('web')->login($this->owner);
        $this->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))->assertRedirect();
        $this->post(route('super-admin.restaurants.dashboard', $this->otherRestaurant))->assertRedirect();

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee('Super Admin Access — Viewing: Other House')
            ->assertSee('Good to see you, Super Admin.')
            ->assertDontSee('Access Owner');

        $this->post(route('super-admin.restaurants.dashboard.return'))
            ->assertRedirect(route('super-admin.restaurants.index'));

        $this->assertTrue(Auth::guard('super_admin')->check());
        $this->assertSame($this->admin->id, Auth::guard('super_admin')->id());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'super_admin_restaurant_access_ended',
            'restaurant_id' => $this->otherRestaurant->id,
        ]);

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertDontSee('Super Admin Access — Viewing:');
    }

    public function test_owner_cannot_enter_admin_access_and_admin_access_cannot_cross_tenant_record_urls(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))
            ->assertRedirect(route('super-admin.login'));

        $this->signInSuperAdmin();
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))->assertRedirect();
        $otherProduct = $this->otherRestaurant->menuItems()->create([
            'name' => 'Private Other Product',
            'price' => '5.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->get(route('restaurant.menu.products.edit', $otherProduct))->assertNotFound();
        $this->get(route('restaurant.profile'))->assertForbidden();
        $this->assertSame($this->restaurant->id, session(RestaurantSupportAccessService::SESSION_ADMIN_ACCESS_RESTAURANT_ID));
    }

    public function test_access_expires_and_ineligible_restaurants_cannot_be_entered(): void
    {
        $this->signInSuperAdmin();
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))->assertRedirect();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->get(route('restaurant.dashboard'))
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
        $this->post(route('super-admin.restaurants.dashboard', $this->restaurant))->assertRedirect();

        $this->post(route('super-admin.logout'))
            ->assertRedirect(route('super-admin.login'));

        $this->assertFalse(Auth::guard('super_admin')->check());
        $this->assertFalse(session()->has(RestaurantSupportAccessService::SESSION_ADMIN_ACCESS_RESTAURANT_ID));
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
