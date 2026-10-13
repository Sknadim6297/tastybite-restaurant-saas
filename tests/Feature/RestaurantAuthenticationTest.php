<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RestaurantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StrongRestaurantPass1!';

    public function test_guest_can_view_the_restaurant_login_page(): void
    {
        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_invalid_restaurant_credentials_receive_a_generic_error(): void
    {
        $this->post(route('restaurant.login.submit'), [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHasErrors(['email'])
            ->assertSessionHas('_old_input.email', 'unknown@example.com')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_valid_restaurant_credentials_regenerate_the_session_and_open_the_dashboard(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->get(route('restaurant.login'));
        $previousSessionId = $this->app['session']->getId();

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])
            ->assertRedirect(route('restaurant.dashboard'))
            ->assertSessionHas('status');

        $this->assertNotSame($previousSessionId, $this->app['session']->getId());
        $this->assertAuthenticatedAs($owner, 'web');

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee($restaurant->name);
    }

    public function test_restaurant_owner_password_is_hashed_at_rest(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->assertNotSame(self::PASSWORD, $owner->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->getRawOriginal('password')));
    }

    public function test_restaurant_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('restaurant.login.submit'), [
                'email' => 'unknown@example.com',
                'password' => self::PASSWORD,
            ])->assertRedirect(route('restaurant.login'));
        }

        $this->post(route('restaurant.login.submit'), [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ])->assertTooManyRequests();
    }

    public function test_restaurant_logout_clears_authentication_and_invalidates_the_session(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $previousCsrfToken = $this->app['session']->token();

        $this->post(route('restaurant.logout'))
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHas('status');

        $this->assertGuest('web');
        $this->assertNotSame($previousCsrfToken, $this->app['session']->token());
    }

    public function test_restaurant_dashboard_is_protected_and_super_admin_access_is_denied(): void
    {
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');

        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->get(route('restaurant.dashboard'))
            ->assertOk();

        $superAdmin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'super@example.com',
            'password' => Hash::make('SuperAdminPass1!'),
            'is_active' => true,
        ]);

        Auth::guard('web')->logout();
        $this->actingAs($superAdmin, 'super_admin')
            ->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');
    }

    public function test_stale_restaurant_session_redirects_once_to_a_usable_login_page(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));

        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');
        $this->assertGuest('web');
    }

    public function test_super_admin_and_restaurant_owner_sessions_coexist_without_a_login_redirect_loop(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);
        $superAdmin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'super@example.com',
            'password' => Hash::make('SuperAdminPass1!'),
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $superAdmin->getAuthPassword()));

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');

        $this->get(route('restaurant.login'))
            ->assertRedirect(route('restaurant.dashboard'));
        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee($restaurant->name);

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');
    }

    public function test_dashboard_and_subscription_status_prefer_the_current_period_over_a_future_renewal(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Renewal House',
            'slug' => 'renewal-house',
            'email' => 'renewal@example.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Renewal Owner',
            'email' => 'renewal-owner@example.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Renewal Plan',
            'code' => 'RENEWAL-TEST',
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $now = CarbonImmutable::now(config('app.timezone'));
        $current = RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'Current Test Period',
            'plan_code' => $plan->code,
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $now->subDay(),
            'expires_at' => $now->addDays(20),
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'Future Renewal Period',
            'plan_code' => $plan->code,
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $now->addDays(21),
            'expires_at' => $now->addDays(51),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee('Current Test Period')
            ->assertDontSee('Future Renewal Period');
        $this->get(route('restaurant.subscription'))
            ->assertOk()
            ->assertSee('Current Test Period')
            ->assertDontSee('Future Renewal Period');
        $this->assertSame($current->id, $restaurant->fresh()->currentSubscriptionAt($now)?->id);
    }

    public function test_disabled_account_and_suspended_restaurant_are_rejected(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->assertAuthenticatedAs($owner, 'web');

        $owner->forceFill(['status' => 'inactive'])->save();
        $this->get(route('restaurant.dashboard'))->assertRedirect(route('restaurant.login'));
        $this->get(route('restaurant.login'))->assertOk()->assertSee('Welcome back');

        $owner->forceFill(['status' => 'active'])->save();
        $restaurant->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');
        $this->get(route('restaurant.login'))->assertOk()->assertSee('Welcome back');

        $owner->forceFill(['status' => 'inactive'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');

        $owner->forceFill(['status' => 'active'])->save();
        $restaurant->forceFill(['status' => 'active'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));
        $restaurant->forceFill(['status' => 'suspended'])->save();
        $this->get(route('restaurant.dashboard'))->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');

        $restaurant->forceFill(['status' => 'active'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));
    }

    public function test_no_public_restaurant_registration_route_exists(): void
    {
        $this->assertFalse(app('router')->has('restaurant.register'));
        $this->get('/restaurant/register')->assertNotFound();
    }
}
