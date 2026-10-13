<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminFeatureManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_management_requires_super_admin_and_lists_registry_and_plan_configuration(): void
    {
        $this->get(route('super-admin.features.index'))
            ->assertRedirect(route('super-admin.login'));

        $this->actingAsSuperAdmin();
        $plan = $this->createPlan();

        $this->get(route('super-admin.features.index'))
            ->assertOk()
            ->assertSee('Point of Sale')
            ->assertSee('Not implemented')
            ->assertSee($plan->name)
            ->assertSee('Feature access history');
    }

    public function test_super_admin_can_configure_feature_and_plan_access_with_audit_history(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $plan = $this->createPlan();
        $feature = SaasFeature::query()->where('code', 'pos')->firstOrFail();

        $this->put(route('super-admin.features.update', $feature), [
            'enabled' => '1',
            'plans' => [
                $plan->id => ['enabled' => '1', 'limit' => '12'],
            ],
        ])->assertRedirect(route('super-admin.features.index'));

        $this->assertDatabaseHas('subscription_plan_features', [
            'subscription_plan_id' => $plan->id,
            'saas_feature_id' => $feature->id,
            'is_enabled' => true,
            'limit_value' => 12,
        ]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'saas_feature_access_updated',
            'target_type' => 'saas_feature',
            'target_id' => $feature->id,
        ]);

        $this->get(route('super-admin.features.index'))
            ->assertOk()
            ->assertSee('Feature access settings updated', false)
            ->assertSee('View audit record');
    }

    public function test_unimplemented_features_cannot_be_enabled_or_mapped_to_a_plan(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->createPlan();
        $feature = SaasFeature::query()->where('code', 'inventory')->firstOrFail();

        $this->put(route('super-admin.features.update', $feature), [
            'enabled' => '1',
            'plans' => [$plan->id => ['enabled' => '1', 'limit' => '']],
        ])->assertForbidden();

        $this->assertFalse($feature->fresh()->is_enabled);
        $this->assertDatabaseMissing('subscription_plan_features', [
            'subscription_plan_id' => $plan->id,
            'saas_feature_id' => $feature->id,
            'is_enabled' => true,
        ]);
    }

    public function test_pos_access_requires_an_active_subscription_global_enablement_and_plan_mapping(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->createPlan();
        $restaurant = $this->createRestaurant('feature-tenant-one');
        $otherRestaurant = $this->createRestaurant('feature-tenant-two');
        $owner = $this->createOwner($restaurant);
        $feature = SaasFeature::query()->where('code', 'pos')->firstOrFail();

        $this->createActiveSubscription($restaurant, $plan);
        $this->createActiveSubscription($otherRestaurant, $plan);
        $plan->features()->syncWithoutDetaching([
            $feature->id => ['is_enabled' => true, 'limit_value' => null],
        ]);

        $this->assertTrue(app(FeatureAccessService::class)->allows($restaurant, 'pos'));
        $this->assertTrue(app(FeatureAccessService::class)->allows($otherRestaurant, 'pos'));

        $this->actingAs($owner, 'web')
            ->withSession([
                'restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword()),
            ])
            ->get(route('pos.index'))
            ->assertOk();

        $plan->features()->updateExistingPivot($feature->id, ['is_enabled' => false]);
        $this->assertFalse(app(FeatureAccessService::class)->allows($restaurant, 'pos'));
        $this->get(route('pos.index'))->assertForbidden();

        $feature->update(['is_enabled' => false]);
        $plan->features()->updateExistingPivot($feature->id, ['is_enabled' => true]);
        $this->assertFalse(app(FeatureAccessService::class)->allows($restaurant, 'pos'));
        $this->get(route('pos.index'))->assertForbidden();
    }

    public function test_plan_feature_access_does_not_leak_between_tenants(): void
    {
        $this->actingAsSuperAdmin();
        $plan = $this->createPlan();
        $restaurant = $this->createRestaurant('feature-isolation-one');
        $otherRestaurant = $this->createRestaurant('feature-isolation-two');
        $feature = SaasFeature::query()->where('code', 'pos')->firstOrFail();
        $this->createActiveSubscription($restaurant, $plan);

        $plan->features()->syncWithoutDetaching([
            $feature->id => ['is_enabled' => true, 'limit_value' => null],
        ]);

        $access = app(FeatureAccessService::class);
        $this->assertTrue($access->allows($restaurant, 'pos'));
        $this->assertFalse($access->allows($otherRestaurant, 'pos'));
    }

    public function test_feature_configuration_validates_plan_access_values(): void
    {
        $this->actingAsSuperAdmin();
        $feature = SaasFeature::query()->where('code', 'pos')->firstOrFail();

        $this->from(route('super-admin.features.index'))
            ->put(route('super-admin.features.update', $feature), [
                'enabled' => '1',
                'plans' => ['not-a-plan' => ['enabled' => 'invalid', 'limit' => '-2']],
            ])
            ->assertSessionHasErrors(['plans.not-a-plan.enabled', 'plans.not-a-plan.limit']);

        $this->assertSame(0, RestaurantAuditLog::query()->where('action', 'saas_feature_access_updated')->count());
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Feature Test Admin',
            'email' => 'feature-admin@example.test',
            'password' => 'StrongAdminPass123!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Feature Test Plan',
            'code' => 'FEATURE-PLAN',
            'price' => '1000.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
        ]);
    }

    private function createRestaurant(string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }

    private function createOwner(Restaurant $restaurant): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Feature Tenant Owner',
            'email' => $restaurant->slug.'-owner@example.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make('StrongRestaurantPass1!'),
        ]);
    }

    private function createActiveSubscription(Restaurant $restaurant, SubscriptionPlan $plan): RestaurantSubscription
    {
        return RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_period' => $plan->billing_period,
            'duration_months' => $plan->duration_months,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }
}
