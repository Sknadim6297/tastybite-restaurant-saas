<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
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

    public function test_payment_entry_is_pending_and_duplicate_method_reference_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();

        $this->post(route('super-admin.payments.store'), $this->paymentInput($restaurant, $plan))
            ->assertRedirect();

        $payment = SubscriptionPayment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('1299.50', $payment->amount);
        $this->assertDatabaseCount('restaurant_subscriptions', 0);
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

    public function test_approval_creates_subscription_and_double_approval_cannot_create_another_period(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin));

        $this->post(route('super-admin.payments.approve', $payment))->assertRedirect();
        $approved = $payment->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->restaurant_subscription_id);
        $this->assertDatabaseCount('restaurant_subscriptions', 1);
        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_subscription_id' => $approved->restaurant_subscription_id,
            'action' => 'assigned',
        ]);
        $this->assertDatabaseHas('restaurant_subscription_events', [
            'restaurant_subscription_id' => $approved->restaurant_subscription_id,
            'details->payment_id' => $payment->id,
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
        $payment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin));

        $this->post(route('super-admin.payments.reject', $payment), ['reason' => 'Transfer could not be verified.'])
            ->assertRedirect();
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertDatabaseCount('restaurant_subscriptions', 0);

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
        $this->get(route('super-admin.payments.reports', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Gross approved payments')
            ->assertSee($plan->name);
        $this->get(route('super-admin.payments.export', ['restaurant_id' => $restaurant->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_demo_payments_are_marked_and_excluded_from_real_financial_summaries(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $demoPayment = SubscriptionPayment::create($this->paymentAttributes($restaurant, $plan, $admin, [
            'status' => 'approved',
            'notes' => 'DEMO DATA ONLY - not actual money',
        ]));

        $this->get(route('super-admin.payments.index', ['scope' => 'demo']))
            ->assertOk()
            ->assertSee('#'.$demoPayment->id)
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

        $this->post(route('super-admin.payments.store'), array_merge(
            $this->paymentInput($restaurant, $plan),
            ['proof' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')],
        ))->assertRedirect();

        $payment = SubscriptionPayment::query()->firstOrFail();
        $this->assertStringStartsWith('payment-proofs/', $payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->get(route('super-admin.payments.proof', $payment))->assertOk();
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

    private function paymentInput(Restaurant $restaurant, SubscriptionPlan $plan): array
    {
        return [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '1299.50',
            'currency' => 'INR',
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
