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

    public function test_subscription_plan_form_renders_safely_for_create_and_edit(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.subscription-plans.create'))
            ->assertOk()
            ->assertSee('Create subscription plan')
            ->assertSee('value="0.00"', false);

        $plan = $this->createPlan([
            'billing_period' => 'custom',
            'duration_months' => null,
            'duration_days' => 45,
            'trial_available' => true,
            'trial_duration_days' => 7,
        ]);

        $this->get(route('super-admin.subscription-plans.edit', $plan))
            ->assertOk()
            ->assertSee('Edit '.$plan->name)
            ->assertSee('value="45"', false)
            ->assertSee('value="7"', false)
            ->assertSee('value="custom" selected', false);
    }

    public function test_plan_configuration_ignores_custom_duration_inputs_for_standard_billing_periods(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('super-admin.subscription-plans.store'), $this->planInput([
            'code' => 'MONTHLY',
            'billing_period' => 'monthly',
            'duration_months' => 'not-used',
            'duration_days' => 'not-used',
        ]))->assertRedirect();

        $plan = SubscriptionPlan::query()->where('code', 'MONTHLY')->firstOrFail();
        $this->assertSame(1, $plan->duration_months);
        $this->assertNull($plan->duration_days);
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

    public function test_assignment_calculates_expiry_from_plan_and_ignores_client_expiry_input(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-01-01 10:00:00', config('app.timezone')));
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Month End House', 'month-end-house');
        $plan = $this->createPlan(['billing_period' => 'monthly', 'duration_months' => 1]);

        $this->post(route('super-admin.subscriptions.store'), [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => '2024-01-31',
            'expiry_date' => '2024-04-30',
            'trial' => '0',
        ])->assertRedirect();

        $subscription = RestaurantSubscription::query()->firstOrFail();
        $this->assertSame(
            '2024-02-28 23:59:59',
            $subscription->expires_at->timezone(config('app.timezone'))->toDateTimeString(),
        );
        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_subscription_id' => $subscription->id,
            'action' => 'assigned',
        ]);
    }

    public function test_plan_expiry_handles_standard_custom_and_trial_calendar_boundaries(): void
    {
        $service = app(SubscriptionService::class);
        $timezone = config('app.timezone');

        $cases = [
            [1, null, '2024-01-31', null, false, '2024-02-28'],
            [3, null, '2024-11-30', null, false, '2025-02-27'],
            [12, null, '2024-02-29', null, false, '2025-02-27'],
            [2, null, '2024-01-31', null, false, '2024-03-30'],
            [null, 45, '2024-01-31', null, false, '2024-03-15'],
            [12, null, '2024-02-26', 7, true, '2024-03-03'],
        ];

        foreach ($cases as [$months, $days, $start, $trialDays, $trial, $expected]) {
            $expiry = $service->planExpiry(
                $months,
                $days,
                CarbonImmutable::createFromFormat('!Y-m-d', $start, $timezone),
                $trialDays,
                $trial,
            );

            $this->assertSame($expected.' 23:59:59', $expiry->toDateTimeString(), "{$start} should expire on {$expected}.");
        }
    }

    public function test_renewal_creates_a_separate_historical_period_and_extension_is_audited(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Renewal House', 'renewal-house');
        $plan = $this->createPlan();
        $service = app(SubscriptionService::class);
        $start = CarbonImmutable::now(config('app.timezone'))->subDay();
        $initial = $service->assign($restaurant, $plan, $start, false, $admin);

        $extensionTarget = $initial->expires_at->timezone(config('app.timezone'))->addDays(10)->startOfDay();
        $service->extend($initial, $extensionTarget, $admin);
        $extended = $initial->fresh();
        $renewal = $service->renew($initial, $admin);

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

    public function test_renewals_use_saved_terms_and_preserve_remaining_paid_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', config('app.timezone')));
        $admin = $this->actingAsSuperAdmin();
        $service = app(SubscriptionService::class);
        $plan = $this->createPlan(['duration_months' => 1]);

        $activeRestaurant = $this->createRestaurant('Active Renewal House', 'active-renewal-house');
        $active = $service->assign(
            $activeRestaurant,
            $plan,
            CarbonImmutable::parse('2026-11-15', config('app.timezone')),
            false,
            $admin,
        );
        $savedExpiry = $active->expires_at;

        $plan->update(['duration_months' => 12, 'billing_period' => 'yearly']);
        $activeRenewal = $service->renew($active, $admin);
        $this->assertSame($savedExpiry->timezone(config('app.timezone'))->startOfDay()->addDay()->utc()->toDateTimeString(), $activeRenewal->starts_at->toDateTimeString());
        $this->assertSame(1, $activeRenewal->duration_months);
        $this->assertSame('2027-01-14 23:59:59', $activeRenewal->expires_at->timezone(config('app.timezone'))->toDateTimeString());
        $this->assertSame('2026-12-14 23:59:59', $active->fresh()->expires_at->timezone(config('app.timezone'))->toDateTimeString());

        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', config('app.timezone')));
        $expiredRestaurant = $this->createRestaurant('Expired Renewal House', 'expired-renewal-house');
        $expiredPlan = $this->createPlan(['duration_months' => 1]);
        $expired = $this->createSubscription($expiredRestaurant, $expiredPlan, [
            'starts_at' => CarbonImmutable::parse('2026-11-01', config('app.timezone'))->utc(),
            'expires_at' => CarbonImmutable::parse('2026-12-14 23:59:59', config('app.timezone'))->utc(),
        ]);

        $expiredRenewal = $service->renew($expired, $admin);
        $this->assertSame('2026-12-20 00:00:00', $expiredRenewal->starts_at->timezone(config('app.timezone'))->toDateTimeString());
        $this->assertSame('2027-01-19 23:59:59', $expiredRenewal->expires_at->timezone(config('app.timezone'))->toDateTimeString());
        $this->assertSame(1, $expiredRenewal->duration_months);
    }

    public function test_renewal_route_ignores_client_expiry_input(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', config('app.timezone')));
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Renewal Override House', 'renewal-override-house');
        $plan = $this->createPlan(['duration_months' => 1]);
        $initial = $this->createSubscription($restaurant, $plan, [
            'starts_at' => CarbonImmutable::parse('2026-10-01', config('app.timezone'))->utc(),
            'expires_at' => CarbonImmutable::parse('2026-10-31 23:59:59', config('app.timezone'))->utc(),
        ]);

        $this->post(route('super-admin.subscriptions.renew', $initial), [
            'expiry_date' => '2026-10-15',
        ])->assertRedirect();

        $renewal = RestaurantSubscription::query()->where('renewal_of_id', $initial->id)->firstOrFail();
        $expectedStart = $initial->expires_at->timezone(config('app.timezone'))->startOfDay()->addDay();
        $expectedExpiry = app(SubscriptionService::class)->planExpiry(
            $initial->duration_months,
            $initial->duration_days,
            $expectedStart,
        );
        $this->assertSame($expectedStart->utc()->toDateTimeString(), $renewal->starts_at->toDateTimeString());
        $this->assertSame($expectedExpiry->utc()->toDateTimeString(), $renewal->expires_at->toDateTimeString());
    }

    public function test_assignment_form_previews_expiry_without_accepting_an_expiry_override(): void
    {
        $this->actingAsSuperAdmin();
        $this->createPlan();

        $response = $this->get(route('super-admin.subscriptions.create'))
            ->assertOk()
            ->assertSee('data-subscription-expiry-preview', false)
            ->assertSee('data-expiry-preview', false)
            ->assertSee('data-duration-months="1"', false)
            ->assertDontSee('name="expiry_date"', false);

        $this->assertStringContainsString('monthly', $response->getContent());
    }

    public function test_renewal_rolls_back_when_its_history_event_cannot_be_written(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Rollback House', 'rollback-house');
        $initial = app(SubscriptionService::class)->assign(
            $restaurant,
            $this->createPlan(),
            CarbonImmutable::now(config('app.timezone'))->subDay(),
            false,
            $admin,
        );
        Event::listen('eloquent.creating: '.RestaurantSubscriptionEvent::class, static function (): void {
            throw new \RuntimeException('History write failed.');
        });

        try {
            app(SubscriptionService::class)->renew($initial, $admin);
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

    public function test_subscription_history_can_be_filtered_by_restaurant_and_retains_filter_in_pagination(): void
    {
        $this->actingAsSuperAdmin();
        $restaurantA = $this->createRestaurant('Restaurant A', 'restaurant-a');
        $restaurantB = $this->createRestaurant('Restaurant B', 'restaurant-b');

        for ($index = 0; $index < 21; $index++) {
            $this->createHistoryEvent($restaurantA);
        }
        $this->createHistoryEvent($restaurantB);

        $allHistory = $this->get(route('super-admin.subscriptions.history'))
            ->assertOk()
            ->assertSee('Restaurant A')
            ->assertSee('Restaurant B')
            ->assertSee('All Restaurants');

        $restaurantAHistory = $this->get(route('super-admin.subscriptions.history', [
            'restaurant_id' => $restaurantA->id,
            'search' => 'assigned',
        ]))
            ->assertOk()
            ->assertSee('data-auto-filter', false)
            ->assertDontSee('type="submit">Search', false)
            ->assertSee('value="'.$restaurantA->id.'" selected', false)
            ->assertSee('value="assigned"', false)
            ->assertSee('assets/super-admin.js');

        $restaurantBHistory = $this->get(route('super-admin.subscriptions.history', [
            'restaurant_id' => $restaurantB->id,
        ]))
            ->assertOk()
            ->assertSee('value="'.$restaurantB->id.'" selected', false);
        $this->assertSame(
            [$restaurantA->id],
            $restaurantAHistory->viewData('events')->getCollection()->pluck('restaurant_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [$restaurantB->id],
            $restaurantBHistory->viewData('events')->getCollection()->pluck('restaurant_id')->unique()->values()->all(),
        );

        $this->get(route('super-admin.subscriptions.history', [
            'restaurant_id' => $restaurantB->id,
            'search' => 'not-a-match',
        ]))
            ->assertOk()
            ->assertSee('No subscription history found for this restaurant.');

        $this->assertStringContainsString(
            'restaurant_id='.$restaurantA->id,
            html_entity_decode($restaurantAHistory->viewData('events')->nextPageUrl() ?? ''),
        );
        $this->assertStringContainsString(
            'search=assigned',
            html_entity_decode($restaurantAHistory->viewData('events')->nextPageUrl() ?? ''),
        );
        $this->assertStringNotContainsString(
            'restaurant_id=',
            html_entity_decode($allHistory->viewData('events')->nextPageUrl() ?? ''),
        );
    }

    private function createHistoryEvent(Restaurant $restaurant): RestaurantSubscriptionEvent
    {
        return RestaurantSubscriptionEvent::create([
            'restaurant_id' => $restaurant->id,
            'action' => 'assigned',
            'new_status' => 'active',
        ]);
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
