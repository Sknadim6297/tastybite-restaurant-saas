<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\DemoDataManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoDataManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_generation_is_linked_marked_idempotent_and_excluded_from_real_reports(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->app->detectEnvironment(fn (): string => 'local');

        $admin = SuperAdmin::create([
            'name' => 'Demo Test Admin',
            'email' => 'demo-test-admin@example.test',
            'password' => 'unique-test-admin-password',
            'is_active' => true,
        ]);
        $realRestaurant = Restaurant::create([
            'name' => 'Real Test Restaurant',
            'slug' => 'real-test-restaurant',
            'email' => 'real-restaurant@example.test',
            'status' => 'active',
            'approval_status' => 'approved',
        ]);
        $starter = SubscriptionPlan::create([
            'name' => 'Starter Plan',
            'code' => 'STARTER',
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);

        $realSubscription = RestaurantSubscription::create([
            'restaurant_id' => $realRestaurant->id,
            'subscription_plan_id' => $starter->id,
            'plan_name' => $starter->name,
            'plan_code' => $starter->code,
            'price' => $starter->price,
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
        $realPayment = SubscriptionPayment::create([
            'restaurant_id' => $realRestaurant->id,
            'subscription_plan_id' => $starter->id,
            'restaurant_subscription_id' => $realSubscription->id,
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'plan_name' => $starter->name,
            'plan_code' => $starter->code,
            'amount' => '499.00',
            'currency' => 'INR',
            'payment_method' => 'other',
            'transaction_reference' => 'REAL-KEEP',
            'status' => 'approved',
            'paid_at' => now(),
            'approved_at' => now(),
        ]);

        $service = app(DemoDataManagementService::class);
        $firstSummary = $service->generate($admin);

        $this->assertSame(15, $firstSummary['counts']['restaurants']);
        $this->assertSame(15, $firstSummary['counts']['owners']);
        $this->assertSame(40, $firstSummary['counts']['restaurant_subscriptions']);
        $this->assertSame(60, $firstSummary['counts']['subscription_payments']);
        $this->assertSame(8, $firstSummary['counts']['subscription_payment_refunds']);
        $this->assertSame(40, $firstSummary['counts']['subscription_invoices']);
        $this->assertSame(80, $firstSummary['counts']['restaurant_subscription_events']);
        $this->assertSame(20, $firstSummary['counts']['support_tickets']);
        $this->assertSame(10, $firstSummary['counts']['platform_announcements']);
        $this->assertSame(30, $firstSummary['counts']['super_admin_notifications']);
        $this->assertSame(50, $firstSummary['counts']['restaurant_audit_logs']);
        $this->assertSame(4, $firstSummary['counts']['subscription_plans']);
        $this->assertTrue($firstSummary['dataset_complete']);
        $this->assertSame([], $firstSummary['integrity_issues']);
        $this->assertSame([
            'active' => 8,
            'cancelled' => 3,
            'expired' => 12,
            'pending_approval' => 5,
            'pending_payment' => 5,
            'scheduled' => 2,
            'suspended' => 3,
            'trial' => 2,
        ], $firstSummary['subscription_statuses']);
        $this->assertSame('499.00', $starter->fresh()->price);
        $this->assertDatabaseHas('subscription_payments', ['id' => $realPayment->id, 'transaction_reference' => 'REAL-KEEP', 'notes' => null]);

        $demoPayments = SubscriptionPayment::query()->where('notes', 'like', 'DEMO DATA ONLY%')->get();
        $this->assertCount(60, $demoPayments);
        $paymentStatuses = $demoPayments->countBy('status')->all();
        ksort($paymentStatuses);
        $this->assertSame(['approved' => 36, 'pending' => 10, 'refunded' => 4, 'rejected' => 10], $paymentStatuses);
        $this->assertTrue($demoPayments->every(
            fn (SubscriptionPayment $payment): bool => $payment->restaurant_id === $payment->subscription->restaurant_id
                && $payment->subscription->restaurant->slug === $payment->restaurant->slug,
        ));
        $this->assertSame(0, DB::table('subscription_payments as p')
            ->join('restaurant_subscriptions as s', 's.id', '=', 'p.restaurant_subscription_id')
            ->where('p.notes', 'like', 'DEMO DATA ONLY%')
            ->whereColumn('p.paid_at', '<', 's.starts_at')
            ->count());
        $this->assertSame(0, DB::table('subscription_invoices')
            ->whereIn('subscription_payment_id', $demoPayments->whereIn('status', ['pending', 'rejected'])->pluck('id'))
            ->count());

        $gross = (int) round((float) $demoPayments->whereIn('status', ['approved', 'refunded'])->sum('amount') * 100);
        $refunds = (int) round((float) SubscriptionPaymentRefund::query()
            ->whereIn('subscription_payment_id', $demoPayments->pluck('id'))
            ->sum('amount') * 100);
        $this->assertLessThanOrEqual($gross, $refunds);

        $secondSummary = $service->generate($admin);
        $this->assertSame($firstSummary['counts'], $secondSummary['counts']);
        $this->assertDatabaseHas('subscription_payments', ['id' => $realPayment->id, 'transaction_reference' => 'REAL-KEEP']);

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));
        $paymentReportResponse = $this->get(route('super-admin.reports.payments', ['scope' => 'demo']))
            ->assertOk()
            ->assertDontSee('Data scope')
            ->assertDontSee('Demo records');
        $paymentReport = $paymentReportResponse->viewData('payments');
        $this->assertSame(1, $paymentReport->total());
        $this->assertSame('REAL-KEEP', $paymentReport->first()->transaction_reference);

        $overview = $this->get(route('super-admin.reports.index', ['scope' => 'demo']))
            ->assertOk()
            ->assertDontSee('Demo records');
        $this->assertSame(1, $overview->viewData('counts')['restaurants']);
        $this->assertSame(1, $overview->viewData('counts')['active_subscriptions']);

        $scheduledSubscriptions = $this->get(route('super-admin.reports.subscriptions', [
            'scope' => 'demo',
            'subscription_status' => 'scheduled',
        ]))->assertOk()->viewData('subscriptions');
        $this->assertSame(0, $scheduledSubscriptions->total());
        $activeSubscriptions = $this->get(route('super-admin.reports.subscriptions', [
            'scope' => 'demo',
            'subscription_status' => 'active',
        ]))->assertOk()->viewData('subscriptions');
        $this->assertSame(1, $activeSubscriptions->total());

        $pendingPayments = $this->get(route('super-admin.reports.payments', [
            'scope' => 'demo',
            'status' => 'pending',
        ]))->assertOk()->viewData('payments');
        $this->assertSame(0, $pendingPayments->total());
        $dateFilteredPayments = $this->get(route('super-admin.reports.payments', [
            'scope' => 'demo',
            'date_from' => now()->subDays(40)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]))->assertOk()->viewData('payments');
        $this->assertSame(1, $dateFilteredPayments->total());
        $revenueReport = $this->get(route('super-admin.reports.revenue', ['scope' => 'demo']))->assertOk();
        $this->assertSame('499.00', $revenueReport->viewData('gross'));
        $this->assertSame(1, $revenueReport->viewData('byPlan')->count());
        $overviewMonths = $overview->viewData('months');
        $this->assertCount(12, $overviewMonths);
        $this->assertGreaterThan(0, array_sum(array_map(fn (array $month): float => (float) $month['gross'], $overviewMonths)));

        $export = $this->get(route('super-admin.reports.export', ['type' => 'payments', 'scope' => 'demo']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Real Test Restaurant', $export->streamedContent());
        $this->assertStringNotContainsString('DEMO-', $export->streamedContent());
        $this->assertStringNotContainsString('Demo Record', $export->streamedContent());

        $cleanupPreview = $service->cleanup($admin);
        $this->assertSame(15, $cleanupPreview['restaurants']);
        $this->assertSame(60, $cleanupPreview['subscription_payments']);
        $this->assertSame(0, $service->preview()['counts']['restaurants']);
        $this->assertSame(0, $service->preview()['counts']['subscription_payments']);
        $this->assertDatabaseHas('restaurants', ['id' => $realRestaurant->id, 'slug' => 'real-test-restaurant']);
        $this->assertDatabaseHas('subscription_payments', ['id' => $realPayment->id, 'transaction_reference' => 'REAL-KEEP']);
    }

    public function test_cleanup_stops_before_deleting_unmarked_records_linked_to_a_demo_tenant(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->app->detectEnvironment(fn (): string => 'local');
        $admin = SuperAdmin::create([
            'name' => 'Cleanup Test Admin',
            'email' => 'cleanup-admin@example.test',
            'password' => 'unique-test-admin-password',
            'is_active' => true,
        ]);
        $service = app(DemoDataManagementService::class);
        $service->generate($admin);
        $subscription = RestaurantSubscription::query()
            ->whereHas('restaurant', fn ($query) => $query->where('slug', 'like', 'demo-tb-2026-%'))
            ->firstOrFail();
        $unmarkedPayment = SubscriptionPayment::create([
            'restaurant_id' => $subscription->restaurant_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'restaurant_subscription_id' => $subscription->id,
            'created_by' => $admin->id,
            'plan_name' => $subscription->plan_name,
            'plan_code' => $subscription->plan_code,
            'amount' => '1.00',
            'currency' => 'INR',
            'payment_method' => 'other',
            'transaction_reference' => 'KEEP-UNMARKED',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        try {
            $service->cleanup($admin);
            $this->fail('Cleanup should stop when an unmarked payment is linked to a demo tenant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unmarked payment', $exception->getMessage());
        }

        $this->assertDatabaseHas('restaurants', ['id' => $subscription->restaurant_id]);
        $this->assertDatabaseHas('subscription_payments', ['id' => $unmarkedPayment->id, 'transaction_reference' => 'KEEP-UNMARKED']);
        $this->assertSame(60, $service->preview()['counts']['subscription_payments']);
    }

    public function test_complete_but_inconsistent_marked_data_is_reported_and_never_overwritten(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->app->detectEnvironment(fn (): string => 'local');
        $admin = SuperAdmin::create([
            'name' => 'Integrity Test Admin',
            'email' => 'integrity-admin@example.test',
            'password' => 'unique-test-admin-password',
            'is_active' => true,
        ]);
        $service = app(DemoDataManagementService::class);
        $service->generate($admin);
        $payment = SubscriptionPayment::query()
            ->where('notes', 'like', 'DEMO DATA ONLY%')
            ->orderBy('id')
            ->firstOrFail();
        $subscription = $payment->subscription;
        $invalidPaidAt = $subscription->starts_at->subDay();
        DB::table('subscription_payments')->where('id', $payment->id)->update([
            'paid_at' => $invalidPaidAt,
        ]);

        $preview = $service->preview();
        $this->assertTrue($preview['dataset_complete']);
        $this->assertSame(1, count($preview['integrity_issues']));
        $this->assertStringContainsString('dated before', $preview['integrity_issues'][0]);

        try {
            $service->generate($admin);
            $this->fail('An inconsistent complete dataset must not be silently regenerated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dated before', $exception->getMessage());
        }

        $this->assertDatabaseHas('subscription_payments', [
            'id' => $payment->id,
            'paid_at' => $invalidPaidAt,
        ]);
        $this->assertSame(60, $service->preview()['counts']['subscription_payments']);
    }
}
