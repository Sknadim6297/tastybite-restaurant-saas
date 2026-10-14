<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuperAdminPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_routes_require_super_admin_authorization(): void
    {
        $this->get(route('super-admin.payments.index'))
            ->assertRedirect(route('super-admin.login'));

        $this->post(route('super-admin.payments.store'), [])
            ->assertRedirect(route('super-admin.login'));
    }

    public function test_payment_id_has_a_padded_professional_display_format(): void
    {
        foreach ([1 => 'PAY000001', 2 => 'PAY000002', 125 => 'PAY000125'] as $id => $expected) {
            $payment = new SubscriptionPayment;
            $payment->setAttribute('id', $id);

            $this->assertSame($expected, $payment->formatted_id);
        }
    }

    public function test_payment_list_and_details_render_saved_values_and_working_actions(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Payment Owner',
            'email' => 'payment-owner@example.test',
            'password' => 'StrongOwnerPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin));

        $this->get(route('super-admin.payments.index'))
            ->assertOk()
            ->assertSee('PAY000001')
            ->assertSee($restaurant->name)
            ->assertSee('Payment Owner')
            ->assertSee($plan->name)
            ->assertSee('INR 1,299.50')
            ->assertSee('UPI')
            ->assertSee('TXN-1')
            ->assertSee('Pending')
            ->assertSee($payment->paid_at->timezone(config('app.timezone'))->format('M j, Y'))
            ->assertSee(route('super-admin.payments.show', $payment), false);

        $this->get(route('super-admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('PAY000001')
            ->assertSee($restaurant->name)
            ->assertSee('Payment Owner')
            ->assertSee($plan->name)
            ->assertSee('TXN-1')
            ->assertSee('Approve payment')
            ->assertSee('Reject payment')
            ->assertSee(route('super-admin.payments.approve', $payment), false)
            ->assertSee(route('super-admin.payments.reject', $payment), false);
    }

    public function test_payment_list_uses_fallbacks_when_optional_relationship_values_are_missing(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'transaction_reference' => null,
            'plan_name' => '',
        ]));

        $this->get(route('super-admin.payments.index'))
            ->assertOk()
            ->assertSee('No owner listed')
            ->assertSee('—')
            ->assertSee($plan->name);
    }

    public function test_payment_entry_is_pending_and_duplicate_method_reference_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($restaurant, $plan);

        $this->post(route('super-admin.payments.store'), $this->paymentInput($restaurant, $plan))
            ->assertRedirect();

        $payment = SubscriptionPayment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('1299.50', $payment->amount);
        $this->assertSame($subscription->id, $payment->restaurant_subscription_id);
        $this->assertSame($subscription->plan_name, $payment->plan_name);
        $this->assertDatabaseCount('restaurant_subscriptions', 1);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'target_type' => 'subscription_payment',
            'target_id' => $payment->id,
            'action' => 'payment_recorded',
        ]);

        $this->from(route('super-admin.payments.create'))
            ->post(route('super-admin.payments.store'), $this->paymentInput($restaurant, $plan))
            ->assertSessionHasErrors('transaction_reference');
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_approval_records_payment_without_changing_subscription_or_creating_another_period(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($restaurant, $plan, $admin);
        $expiryBefore = $subscription->expires_at->toDateTimeString();
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin));

        $this->from(route('super-admin.notifications.feed'))
            ->post(route('super-admin.payments.approve', $payment))
            ->assertRedirect(route('super-admin.payments.show', $payment))
            ->assertSessionHas('status', 'Payment approved successfully.');
        $approved = $payment->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame($subscription->id, $approved->restaurant_subscription_id);
        $this->assertDatabaseCount('restaurant_subscriptions', 1);
        $this->assertSame($expiryBefore, $subscription->fresh()->expires_at->toDateTimeString());
        $this->assertDatabaseHas('subscription_invoices', [
            'subscription_payment_id' => $payment->id,
            'restaurant_subscription_id' => $subscription->id,
        ]);

        $this->from(route('super-admin.payments.show', $payment))
            ->post(route('super-admin.payments.approve', $payment))
            ->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('restaurant_subscriptions', 1);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'target_type' => 'subscription_payment',
            'target_id' => $payment->id,
            'action' => 'payment_approved',
        ]);
    }

    public function test_rejection_does_not_create_subscription_and_invalid_refund_is_rejected(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin));

        $this->post(route('super-admin.payments.reject', $payment), ['reason' => 'Transfer could not be verified.'])
            ->assertRedirect();
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertDatabaseCount('restaurant_subscriptions', 1);

        $approved = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'status' => 'approved',
            'transaction_reference' => 'TXN-2',
        ]));
        $this->from(route('super-admin.payments.show', $approved))
            ->post(route('super-admin.payments.refunds.store', $approved), $this->refundInput('1299.51'))
            ->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('subscription_payment_refunds', 0);
    }

    public function test_partial_and_full_refunds_are_decimal_safe_audited_and_immutable(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'status' => 'approved',
        ]));

        $this->post(route('super-admin.payments.refunds.store', $payment), $this->refundInput('299.50'))
            ->assertRedirect();
        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame('1000.00', $payment->fresh()->remainingRefundableAmount());

        $this->post(route('super-admin.payments.refunds.store', $payment), $this->refundInput('1000.00', [
            'reference' => 'REFUND-2',
        ]))->assertRedirect();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertDatabaseCount('subscription_payment_refunds', 2);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'target_type' => 'subscription_payment',
            'target_id' => $payment->id,
            'action' => 'payment_refund_recorded',
        ]);

        $this->expectException(\LogicException::class);
        SubscriptionPaymentRefund::query()->firstOrFail()->delete();
    }

    public function test_filters_reports_and_csv_export_use_recorded_payments(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'status' => 'approved',
        ]));

        $this->get(route('super-admin.payments.index', ['status' => 'approved', 'restaurant_id' => $restaurant->id]))
            ->assertOk()
            ->assertSee($restaurant->name)
            ->assertSee('TXN-1');
        $this->get(route('super-admin.payments.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertDontSee('TXN-1');
        $today = now(config('app.timezone'))->format('Y-m-d');
        $this->get(route('super-admin.payments.index', ['date_from' => $today, 'date_to' => $today]))
            ->assertOk()
            ->assertSee('TXN-1');
        $this->get(route('super-admin.payments.index', ['date_to' => $today]))
            ->assertOk()
            ->assertSee('data-auto-filter', false);
        $this->get(route('super-admin.payments.reports', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Gross approved payments')
            ->assertSee($plan->name)
            ->assertSee('data-auto-filter', false);
        $this->get(route('super-admin.reports.payments'))
            ->assertOk()
            ->assertSee('PAY000001')
            ->assertSee($restaurant->name);
        $this->get(route('super-admin.payments.export', ['restaurant_id' => $restaurant->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_demo_payments_are_marked_and_excluded_from_real_financial_summaries(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);
        $demoPayment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'status' => 'approved',
            'notes' => 'DEMO DATA ONLY - not actual money',
        ]));

        $this->get(route('super-admin.payments.index', ['scope' => 'demo']))
            ->assertOk()
            ->assertSee($demoPayment->formatted_id)
            ->assertSee('Demo');
        $this->get(route('super-admin.payments.reports'))
            ->assertOk()
            ->assertSee('Gross approved payments (real scope)')
            ->assertSee('INR 0.00');
        $this->get(route('super-admin.payments.reports', ['scope' => 'demo']))
            ->assertOk()
            ->assertSee('Gross approved payments (demo scope)')
            ->assertSee('INR 1,299.50');
        $this->get(route('super-admin.payments.show', $demoPayment))
            ->assertOk()
            ->assertSee('DEMO ONLY — NOT ACTUAL MONEY');
    }

    public function test_payment_proof_is_stored_in_private_local_disk(): void
    {
        Storage::fake('local');
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->createSubscription($restaurant, $plan, $admin);

        $this->post(route('super-admin.payments.store'), array_merge(
            $this->paymentInput($restaurant, $plan),
            ['proof' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')],
        ))->assertRedirect();

        $payment = SubscriptionPayment::query()->firstOrFail();
        $this->assertStringStartsWith('payment-proofs/', $payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->get(route('super-admin.payments.proof', $payment))->assertOk();
    }

    public function test_restaurant_subscription_endpoint_returns_saved_subscription_and_payment_summary(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($restaurant, $plan);
        $payment = SubscriptionPayment::create($this->paymentAttributes(
            $restaurant,
            $plan,
            auth('super_admin')->user(),
            ['restaurant_subscription_id' => $subscription->id, 'status' => 'approved', 'amount' => '300.00'],
        ));

        $this->get(route('super-admin.payments.restaurants.subscriptions', $restaurant))
            ->assertOk()
            ->assertJsonPath('restaurant.name', $restaurant->name)
            ->assertJsonPath('owner', null)
            ->assertJsonPath('subscriptions.0.id', $subscription->id)
            ->assertJsonPath('subscriptions.0.price', '1299.50')
            ->assertJsonPath('subscriptions.0.already_paid', '300.00')
            ->assertJsonPath('subscriptions.0.outstanding', '999.50');

        $this->assertSame($subscription->id, $payment->fresh()->restaurant_subscription_id);
    }

    public function test_payment_creation_rejects_subscription_from_another_restaurant_and_overpayment(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $otherRestaurant = Restaurant::create([
            'name' => 'Other Payment Restaurant',
            'slug' => 'other-payment-restaurant',
            'email' => 'other-payment@example.test',
            'status' => 'active',
        ]);
        $plan = $this->createPlan();
        $otherPlan = SubscriptionPlan::create([
            'name' => 'Other Monthly Plan',
            'code' => 'OTHER-MONTHLY-PLAN',
            'price' => '1299.50',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
        ]);
        $subscription = $this->createSubscription($otherRestaurant, $otherPlan);
        $ownSubscription = $this->createSubscription($restaurant, $plan);

        $this->from(route('super-admin.payments.create'))
            ->post(route('super-admin.payments.store'), array_merge(
                $this->paymentInput($restaurant, $plan),
                ['restaurant_subscription_id' => $subscription->id],
            ))
            ->assertSessionHasErrors('restaurant_subscription_id');

        $this->from(route('super-admin.payments.create'))
            ->post(route('super-admin.payments.store'), array_merge(
                $this->paymentInput($restaurant, $plan),
                ['restaurant_subscription_id' => $ownSubscription->id, 'amount' => '1299.51'],
            ))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_approval_of_a_payment_linked_to_an_active_subscription_does_not_renew_or_change_dates(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($restaurant, $plan, $admin);
        $before = $subscription->fresh()->only(['starts_at', 'expires_at', 'status']);
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'restaurant_subscription_id' => $subscription->id,
        ]));

        $this->post(route('super-admin.payments.approve', $payment))->assertRedirect();

        $this->assertSame(1, RestaurantSubscription::query()->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame($before['starts_at']->toDateTimeString(), $subscription->fresh()->starts_at->toDateTimeString());
        $this->assertSame($before['expires_at']->toDateTimeString(), $subscription->fresh()->expires_at->toDateTimeString());
        $this->assertSame($before['status'], $subscription->fresh()->status);
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Payments Admin',
            'email' => 'payments-admin@example.test',
            'password' => 'StrongAdminPass123!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createRestaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Payment Test Restaurant',
            'slug' => 'payment-test-restaurant',
            'email' => 'payment-test@example.test',
            'status' => 'active',
        ]);
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Monthly Plan',
            'code' => 'MONTHLY-PLAN',
            'price' => '1299.50',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
        ]);
    }

    private function createSubscription(
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        ?SuperAdmin $admin = null,
    ): RestaurantSubscription {
        $admin ??= auth('super_admin')->user() ?? $this->actingAsSuperAdmin();
        $startsAt = now()->startOfDay();

        return RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_period' => $plan->billing_period,
            'duration_months' => $plan->duration_months,
            'duration_days' => $plan->duration_days,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addMonth()->endOfDay(),
            'created_by' => $admin->id,
        ]);
    }

    private function paymentInput(Restaurant $restaurant, SubscriptionPlan $plan): array
    {
        $subscription = RestaurantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('subscription_plan_id', $plan->id)
            ->latest('id')
            ->firstOrFail();

        return [
            'restaurant_id' => $restaurant->id,
            'restaurant_subscription_id' => $subscription->id,
            'amount' => '1299.50',
            'payment_method' => 'upi',
            'transaction_reference' => 'TXN-1',
            'paid_at' => now(config('app.timezone'))->format('Y-m-d H:i:s'),
            'notes' => 'Test payment',
        ];
    }

    private function paymentAttributes(Restaurant $restaurant, SubscriptionPlan $plan, SuperAdmin $admin, array $overrides = []): array
    {
        return array_merge([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'restaurant_subscription_id' => RestaurantSubscription::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('subscription_plan_id', $plan->id)
                ->latest('id')
                ->value('id'),
            'created_by' => $admin->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'amount' => '1299.50',
            'currency' => 'INR',
            'payment_method' => 'upi',
            'transaction_reference' => 'TXN-1',
            'status' => 'pending',
            'paid_at' => now()->utc(),
        ], $overrides);
    }

    private function refundInput(string $amount, array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference' => 'REFUND-1',
            'refunded_at' => now(config('app.timezone'))->format('Y-m-d H:i:s'),
            'reason' => 'Manual refund confirmed by finance.',
        ], $overrides);
    }
}
