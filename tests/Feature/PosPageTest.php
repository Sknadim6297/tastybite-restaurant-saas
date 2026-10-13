<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::create([
            'name' => 'POS Test Restaurant',
            'slug' => 'pos-test-restaurant',
            'email' => 'pos@example.test',
            'status' => 'active',
        ]);
        $user = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'POS Test Owner',
            'email' => 'pos-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'POS Test Plan',
            'code' => 'POS-TEST',
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
            'plan_name' => 'POS Test Plan',
            'plan_code' => 'POS-TEST',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);

        $this->actingAs($user, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $user->getAuthPassword()));
    }

    public function test_pos_page_renders_with_local_assets_without_a_print_html_page(): void
    {
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Tasty Bite POS')
            ->assertSee('Billing (POS)')
            ->assertSee(asset('assets/style.css'), false)
            ->assertSee(asset('assets/script.js'), false)
            ->assertDontSee('/pos-print.html');

        $this->get('/pos-print.html')->assertNotFound();
    }

    public function test_print_html_page_is_not_available(): void
    {
        $this->get('/pos-print.html')->assertNotFound();
    }
}
