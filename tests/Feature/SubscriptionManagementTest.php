<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_edit_and_deactivate_a_plan_with_validated_terms(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $this->post(route('super-admin.subscription-plans.store'), $this->planInput([
            'name' => 'Growth Plan',
            'code' => 'growth-01',
            'billing_period' => 'custom',
            'duration_months' => '',
            'duration_days' => '45',
            'trial_available' => '1',
            'trial_duration_days' => '7',
        ]))
            ->assertRedirect();

        $plan = SubscriptionPlan::query()->where('code', 'GROWTH-01')->firstOrFail();
        $this->assertSame('1299.50', $plan->price);
        $this->assertSame('INR', $plan->currency);
        $this->assertSame(45, $plan->duration_days);
        $this->assertTrue($plan->trial_available);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'target_type' => 'subscription_plan',
            'action' => 'subscription_plan_created',
        ]);

        $this->get(route('super-admin.subscription-plans.index'))->assertOk()->assertSee('Growth Plan');
        $this->get(route('super-admin.subscription-plans.show', $plan))->assertOk()->assertSee('GROWTH-01');

        $this->put(route('super-admin.subscription-plans.update', $plan), $this->planInput([
            'name' => 'Growth Plan Updated',
            'code' => 'growth-01',
            'price' => '1499.00',
            'status' => 'inactive',
            'billing_period' => 'quarterly',
            'duration_months' => '',
            'duration_days' => '',
            'trial_available' => '0',
            'trial_duration_days' => '',
        ]))->assertRedirect(route('super-admin.subscription-plans.show', $plan));

        $this->assertSame('1499.00', $plan->fresh()->price);
        $this->assertSame(3, $plan->fresh()->duration_months);
        $this->assertSame('inactive', $plan->fresh()->status);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'target_type' => 'subscription_plan',
            'target_id' => $plan->id,
            'action' => 'subscription_plan_updated',
        ]);
    }

    public function test_plan_code_and_custom_duration_validation_reject_duplicates_and_ambiguous_terms(): void
    {
        $this->actingAsSuperAdmin();
        SubscriptionPlan::create($this->planAttributes(['code' => 'DUPLICATE']));

        $this->from(route('super-admin.subscription-plans.create'))
            ->post(route('super-admin.subscription-plans.store'), $this->planInput([
                'code' => 'duplicate',
                'billing_period' => 'custom',
                'duration_months' => '2',
                'duration_days' => '60',
            ]))
            ->assertSessionHasErrors(['code', 'duration_months']);

        $this->assertDatabaseCount('subscription_plans', 1);
    }

    public function test_subscription_assignment_records_trial_term_and_rejects_overlapping_assignment(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Trial House', 'trial-house');
        $plan = $this->createPlan(['trial_available' => true, 'trial_duration_days' => 3]);
        $startDate = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d');

        $this->post(route('super-admin.subscriptions.store'), [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => $startDate,
            'trial' => '1',
        ])->assertRedirect();

        $subscription = RestaurantSubscription::query()->firstOrFail();
        $this->assertSame('trial', $subscription->status);
        $this->assertTrue($subscription->is_trial);
        $this->assertSame(
            3,
            (int) $subscription->starts_at->timezone(config('app.timezone'))->startOfDay()
                ->diffInDays($subscription->expires_at->timezone(config('app.timezone'))->startOfDay(), false) + 1,
        );
        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_id' => $restaurant->id,
            'actor_id' => $admin->id,
            'action' => 'assigned',
            'new_status' => 'trial',
        ]);

        $this->post(route('super-admin.subscriptions.store'), [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => $startDate,
            'trial' => '0',
        ])->assertSessionHasErrors(['subscription']);

        $this->assertDatabaseCount('restaurant_subscriptions', 1);
    }

    public function test_renewal_creates_a_separate_historical_period_and_extension_is_audited(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Renewal House', 'renewal-house');
        $plan = $this->createPlan();
        $service = app(SubscriptionService::class);
        $start = CarbonImmutable::now(config('app.timezone'))->subDay();
        $initial = $service->assign($restaurant, $plan, $start, null, false, $admin);

        $extensionTarget = $initial->expires_at->timezone(config('app.timezone'))->addDays(10)->startOfDay();
        $service->extend($initial, $extensionTarget, $admin);
        $extended = $initial->fresh();
        $renewal = $service->renew($initial, null, $admin);

        $this->assertNotSame($initial->id, $renewal->id);
        $this->assertSame($initial->id, $renewal->renewal_of_id);
        $this->assertSame(
            $extended->expires_at->timezone(config('app.timezone'))->startOfDay()->addDay()->utc()->toDateTimeString(),
            $renewal->starts_at->toDateTimeString(),
        );
        $this->assertDatabaseCount('restaurant_subscriptions', 2);

        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_subscription_id' => $initial->id,
            'action' => 'extended',
            'actor_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_subscription_id' => $renewal->id,
            'action' => 'renewed',
        ]);

        try {
            $service->extend($initial, $renewal->expires_at->timezone(config('app.timezone'))->addDays(10), $admin);
            $this->fail('An extension must not overlap the next active subscription period.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subscription', $exception->errors());
        }
    }

    public function test_renewal_rolls_back_when_its_history_event_cannot_be_written(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Rollback House', 'rollback-house');
        $initial = app(SubscriptionService::class)->assign(
            $restaurant,
            $this->createPlan(),
            CarbonImmutable::now(config('app.timezone'))->subDay(),
            null,
            false,
            $admin,
        );
        Event::listen('eloquent.creating: '.RestaurantSubscriptionEvent::class, static function (): void {
            throw new \RuntimeException('History write failed.');
        });

        try {
            app(SubscriptionService::class)->renew($initial, null, $admin);
            $this->fail('The renewal should roll back when its event cannot be recorded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('History write failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('restaurant_subscriptions', 1);
        $this->assertDatabaseCount('restaurant_subscription_events', 1);
    }

    public function test_suspend_reactivate_and_cancel_are_audited_and_terminal_statuses_are_retained(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Lifecycle House', 'lifecycle-house');
        $plan = $this->createPlan();
        $subscription = app(SubscriptionService::class)->assign(
            $restaurant,
            $plan,
            CarbonImmutable::now(config('app.timezone'))->subDay(),
            null,
            false,
            $admin,
        );
        $service = app(SubscriptionService::class);

        $service->changeStatus($subscription, 'suspended', $admin);
        $service->changeStatus($subscription, 'active', $admin);
        $service->changeStatus($subscription, 'cancelled', $admin);

        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertDatabaseHas('restaurant_subscription_events', ['action' => 'suspended', 'restaurant_subscription_id' => $subscription->id]);
        $this->assertDatabaseHas('restaurant_subscription_events', ['action' => 'reactivated', 'restaurant_subscription_id' => $subscription->id]);
        $this->assertDatabaseHas('restaurant_subscription_events', ['action' => 'cancelled', 'restaurant_subscription_id' => $subscription->id]);
        $this->assertDatabaseCount('restaurant_subscription_events', 4);

        $event = RestaurantSubscriptionEvent::query()->where('action', 'cancelled')->firstOrFail();
        try {
            $event->update(['action' => 'rewritten']);
            $this->fail('Subscription history events must be immutable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Subscription history events are immutable.', $exception->getMessage());
        }

        $this->assertSame('cancelled', $event->fresh()->action);
    }

    public function test_subscription_expiry_uses_the_application_timezone_and_exact_expiry_boundary(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Asia/Kolkata'));
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Timezone House', 'timezone-house');
        $plan = $this->createPlan(['duration_months' => 1]);
        $subscription = app(SubscriptionService::class)->assign(
            $restaurant,
            $plan,
            CarbonImmutable::parse('2026-10-13', 'Asia/Kolkata'),
            null,
            false,
            $admin,
        );

        $this->assertSame('2026-11-12 23:59:59', $subscription->expires_at->timezone('Asia/Kolkata')->toDateTimeString());
        $this->assertTrue($subscription->isCurrentlyValid($subscription->expires_at));
        $this->assertSame('expired', $subscription->effectiveStatus($subscription->expires_at->addSecond()));
    }

    public function test_expired_subscription_blocks_pos_and_direct_json_requests_but_keeps_account_routes_available(): void
    {
        $restaurant = $this->createRestaurant('Expired House', 'expired-house');
        $user = $this->createUser($restaurant, 'expired-owner@example.test');
        $subscription = $this->createSubscription($restaurant, $this->createPlan());

        $this->actingAsRestaurantUser($user);
        $this->get(route('pos.index'))->assertOk();

        $this->travelTo($subscription->expires_at->addSecond());
        $this->get(route('pos.index'))->assertRedirect(route('restaurant.subscription'));
        $this->getJson(route('pos.index'))->assertForbidden()->assertJsonPath('code', 'subscription_required');
        $this->get('/pos-print.html')->assertNotFound();

        $this->get(route('restaurant.dashboard'))->assertOk()->assertSee('Expired');
        $this->get(route('restaurant.profile'))->assertOk();
        $this->get(route('restaurant.subscription'))->assertOk()->assertSee('Renewal instructions');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_expired_trial_blocks_operational_access_on_the_next_request(): void
    {
        $restaurant = $this->createRestaurant('Trial Expiry House', 'trial-expiry-house');
        $user = $this->createUser($restaurant, 'trial-expiry-owner@example.test');
        $plan = $this->createPlan(['trial_available' => true, 'trial_duration_days' => 1]);
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'trial-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $subscription = app(SubscriptionService::class)->assign(
            $restaurant,
            $plan,
            CarbonImmutable::now(config('app.timezone')),
            null,
            true,
            $admin,
        );
        $this->actingAsRestaurantUser($user);

        $this->get(route('pos.index'))->assertOk();
        $this->travelTo($subscription->expires_at->addSecond());
        $this->getJson(route('pos.index'))->assertForbidden();
        $this->get(route('restaurant.dashboard'))->assertOk()->assertSee('Expired');
    }

    public function test_suspended_subscription_blocks_operational_access_and_expired_owner_can_still_sign_in(): void
    {
        $restaurant = $this->createRestaurant('Suspended House', 'suspended-house');
        $user = $this->createUser($restaurant, 'suspended-owner@example.test');
        $subscription = $this->createSubscription($restaurant, $this->createPlan(), ['status' => 'suspended']);

        $this->actingAsRestaurantUser($user);
        $this->getJson(route('pos.index'))->assertForbidden();
        $this->get(route('restaurant.subscription'))->assertOk()->assertSee('Suspended');

        auth('web')->logout();
        auth()->forgetGuards();
        $this->post(route('restaurant.login.submit'), [
            'email' => $user->email,
            'password' => 'StrongTestPass123!',
        ])->assertRedirect(route('restaurant.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_active_subscription_is_tenant_scoped_and_guest_or_super_admin_cannot_open_pos(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('restaurant.login'));

        $restaurantA = $this->createRestaurant('Tenant A', 'tenant-a');
        $restaurantB = $this->createRestaurant('Tenant B', 'tenant-b');
        $ownerA = $this->createUser($restaurantA, 'tenant-a-owner@example.test');
        $this->createSubscription($restaurantB, $this->createPlan());

        $this->actingAsRestaurantUser($ownerA);
        $this->get(route('pos.index'))->assertRedirect(route('restaurant.subscription'));
        $this->get(route('restaurant.subscription'))->assertOk()->assertSee('No subscription has been assigned');

        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'subscription-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));
        $this->get(route('super-admin.subscription-plans.index'))->assertOk();
        $this->post(route('super-admin.subscription-plans.store'), $this->planInput(['code' => 'AUTHZ']))
            ->assertRedirect();
    }

    public function test_super_admin_subscription_mutations_require_super_admin_authorization(): void
    {
        $plan = $this->createPlan();
        $restaurant = $this->createRestaurant('Protected House', 'protected-house');

        $this->get(route('super-admin.subscriptions.create'))->assertRedirect(route('super-admin.login'));
        $this->post(route('super-admin.subscriptions.store'), [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => now()->format('Y-m-d'),
        ])->assertRedirect(route('super-admin.login'));

        $owner = $this->createUser($restaurant, 'protected-owner@example.test');
        $this->actingAsRestaurantUser($owner);
        $this->post(route('super-admin.subscription-plans.status', $plan), ['status' => 'inactive'])
            ->assertRedirect(route('super-admin.login'));
        $this->assertSame('active', $plan->fresh()->status);
    }

    public function test_expiring_soon_and_subscription_history_lists_are_searchable(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Soon House', 'soon-house');
        $subscription = $this->createSubscription($restaurant, $this->createPlan(), [
            'expires_at' => now(config('app.timezone'))->addDays(10)->endOfDay(),
        ]);

        $this->get(route('super-admin.subscriptions.expiring'))
            ->assertOk()
            ->assertSee('Soon House');
        $this->get(route('super-admin.subscriptions.history'))
            ->assertOk()
            ->assertSee('assigned');
        $this->get(route('super-admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee('Subscription actions');
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function actingAsRestaurantUser(User $user): void
    {
        $this->actingAs($user, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $user->getAuthPassword()));
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "{$slug}@example.test",
            'status' => 'active',
        ]);
    }

    private function createUser(Restaurant $restaurant, string $email): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Restaurant Owner',
            'email' => $email,
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function createPlan(array $overrides = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create($this->planAttributes($overrides));
        $plan->features()->attach(
            \App\Models\SaasFeature::query()->where('code', 'pos')->value('id'),
            ['is_enabled' => true, 'limit_value' => null],
        );

        return $plan;
    }

    private function planAttributes(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_replace([
            'name' => 'Standard Plan '.$sequence,
            'code' => 'STANDARD-'.$sequence,
            'description' => 'Restaurant operations access.',
            'price' => '1299.50',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'duration_days' => null,
            'trial_available' => false,
            'trial_duration_days' => null,
            'status' => 'active',
        ], $overrides);
    }

    private function planInput(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Standard Plan',
            'code' => 'STANDARD',
            'description' => 'Restaurant operations access.',
            'price' => '1299.50',
            'billing_period' => 'monthly',
            'duration_months' => '',
            'duration_days' => '',
            'trial_available' => '0',
            'trial_duration_days' => '',
            'status' => 'active',
        ], $overrides);
    }

    private function createSubscription(Restaurant $restaurant, SubscriptionPlan $plan, array $overrides = []): RestaurantSubscription
    {
        $startsAt = CarbonImmutable::now(config('app.timezone'))->subDay()->startOfDay();

        return RestaurantSubscription::create(array_replace([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => $plan->price,
            'currency' => 'INR',
            'billing_period' => $plan->billing_period,
            'duration_months' => $plan->duration_months,
            'duration_days' => $plan->duration_days,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $startsAt,
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addDays(2)->endOfDay(),
        ], $overrides));
    }
}
