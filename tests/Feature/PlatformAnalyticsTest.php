<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_uses_real_records_and_decimal_payment_refund_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', config('app.timezone')));
        $admin = $this->actingAsSuperAdmin();
        $realRestaurant = $this->createRestaurant('Real Analytics House', 'real-analytics-house', '2026-10-10');
        $demoRestaurant = $this->createRestaurant('Demo Analytics House', 'demo-tb-2026-analytics-house', '2026-10-10');
        $plan = SubscriptionPlan::create([
            'name' => 'Analytics Plan',
            'code' => 'ANALYTICS-PLAN',
            'price' => '100.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $realRestaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => '100.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
        $payment = $this->createPayment($realRestaurant, $plan, $admin, '99.99', 'INR', 'approved', null);
        SubscriptionPaymentRefund::create([
            'subscription_payment_id' => $payment->id,
            'admin_id' => $admin->id,
            'amount' => '19.99',
            'method' => 'cash',
            'reference' => 'ANALYTICS-REFUND-1',
            'refunded_at' => now(),
            'reason' => 'Test report refund.',
        ]);
        $this->createPayment($demoRestaurant, $plan, $admin, '200.00', 'INR', 'approved', 'DEMO DATA ONLY; not actual collected money');

        $realResponse = $this->get(route('super-admin.analytics.index', [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-13',
        ]))->assertOk();
        $realResponse->assertViewHas('summary', fn (array $summary): bool => $summary['new_restaurants'] === 1
            && $summary['new_subscriptions'] === 1
            && $summary['active_subscriptions'] === 1);
        $realResponse->assertViewHas('revenue', fn ($rows): bool => $rows->contains(fn (array $row): bool => $row === [
            'currency' => 'INR',
            'gross' => '99.99',
            'refunds' => '19.99',
            'net' => '80.00',
        ]));

        $scopeAttemptResponse = $this->get(route('super-admin.analytics.index', [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-13',
            'scope' => 'demo',
        ]))->assertOk()->assertDontSee('Data scope')->assertDontSee('Demo records');
        $scopeAttemptResponse->assertViewHas('summary', fn (array $summary): bool => $summary['new_restaurants'] === 1
            && $summary['new_subscriptions'] === 1
            && $summary['active_subscriptions'] === 1);
        $scopeAttemptResponse->assertViewHas('revenue', fn ($rows): bool => $rows->contains(fn (array $row): bool => $row['gross'] === '99.99')
            && ! $rows->contains(fn (array $row): bool => $row['gross'] === '200.00'));

        $this->get(route('super-admin.analytics.index', ['date_to' => '2026-10-13']))
            ->assertOk()
            ->assertSee('data-auto-filter', false);
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Analytics Admin',
            'email' => 'analytics-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createRestaurant(string $name, string $slug, string $createdAt): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
            'created_at' => CarbonImmutable::parse($createdAt.' 10:00:00', config('app.timezone')),
            'updated_at' => CarbonImmutable::parse($createdAt.' 10:00:00', config('app.timezone')),
        ]);
    }

    private function createPayment(
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        SuperAdmin $admin,
        string $amount,
        string $currency,
        string $status,
        ?string $notes,
    ): SubscriptionPayment {
        return SubscriptionPayment::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'ANALYTICS-'.$restaurant->id,
            'status' => $status,
            'paid_at' => now(),
            'approved_at' => now(),
            'notes' => $notes,
        ]);
    }
}
