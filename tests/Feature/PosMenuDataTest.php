<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosMenuDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_page_loads_only_the_authenticated_restaurant_menu(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bluebird Bistro',
            'slug' => 'bluebird-bistro',
            'email' => 'bluebird@example.test',
            'status' => 'active',
        ]);

        $foreignRestaurant = Restaurant::create([
            'name' => 'Greenleaf Grill',
            'slug' => 'greenleaf-grill',
            'email' => 'greenleaf@example.test',
            'status' => 'active',
        ]);

        $user = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Bluebird Manager',
            'email' => 'manager@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'POS Access',
            'code' => 'POS-ACCESS',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);

        $plan->features()->attach(SaasFeature::query()->where('code', 'pos')->value('id'), [
            'is_enabled' => true,
            'limit_value' => null,
        ]);

        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'POS Access',
            'plan_code' => 'POS-ACCESS',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);

        $category = RestaurantMenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Biryani',
            'slug' => 'biryani',
            'description' => 'House favourites',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $otherCategory = RestaurantMenuCategory::create([
            'restaurant_id' => $foreignRestaurant->id,
            'name' => 'Beverages',
            'slug' => 'beverages',
            'description' => 'Other restaurant menu',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        RestaurantMenuItem::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Chicken Dum Biryani',
            'description' => 'Signature rice dish',
            'price' => '199.00',
            'image_path' => null,
            'food_type' => 'non_veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        RestaurantMenuItem::create([
            'restaurant_id' => $foreignRestaurant->id,
            'category_id' => $otherCategory->id,
            'name' => 'Cold Coffee',
            'description' => 'Other restaurant menu',
            'price' => '120.00',
            'image_path' => null,
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($user, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $user->getAuthPassword()));

        $response = $this->get(route('pos.index'));

        $response->assertOk()
            ->assertSee('Bluebird Bistro')
            ->assertSee('Bluebird Manager')
            ->assertSee('Owner')
            ->assertSee('Chicken Dum Biryani')
            ->assertSee('food-status', false)
            ->assertSee('Biryani')
            ->assertSee('data-search-clear', false)
            ->assertSee('No matching products')
            ->assertSee('id="currentDate"', false)
            ->assertSee('id="currentTime"', false)
            ->assertDontSee('notification-dot', false)
            ->assertDontSee('No new notifications.', false)
            ->assertDontSee('Cold Coffee');

        $script = file_get_contents(public_path('assets/script.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('const timeZone = "Asia/Kolkata";', $script);
        $this->assertStringContainsString('.trim().replace(/\\s+/g, " ").toLocaleLowerCase()', $script);
        $this->assertStringContainsString('card.hidden = !isVisible;', $script);
        $this->assertStringContainsString('noMatchingProducts.hidden = visibleCount !== 0;', $script);
        $this->assertSame(1, substr_count($script, 'setInterval(updateClock, 1000)'));
        $this->assertStringContainsString('hour12: true', $script);
    }
}
