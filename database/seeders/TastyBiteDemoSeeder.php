<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\PaymentManagementService;
use App\Services\RestaurantProvisioningService;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class TastyBiteDemoSeeder extends Seeder
{
    private const MARKER = 'DEMO-TB-2026';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('TastyBiteDemoSeeder may only run in the local environment.');
        }

        $password = env('LOCAL_DEMO_OWNER_PASSWORD');
        if (! is_string($password) || mb_strlen($password) < 12) {
            throw new RuntimeException('Set a local LOCAL_DEMO_OWNER_PASSWORD of at least 12 characters before seeding.');
        }

        $this->assertSchema();
        $admin = SuperAdmin::query()->where('email', 'superadmin@gmail.com')->first();
        if (! $admin) {
            throw new RuntimeException('The existing Super Admin account is missing. No demo data was written.');
        }

        DB::transaction(function () use ($password, $admin): void {
            $plans = $this->ensurePlans($admin);
            $restaurants = $this->ensureRestaurants($password, $admin);

            $this->ensurePaymentWorkflows($restaurants, $plans, $admin);
            $this->ensureTrialSubscription($restaurants['bengali'], $plans['basic'], $admin);
            $this->ensureExpiringSubscription($restaurants['spice'], $plans['pro'], $admin);
            $this->ensureExpiredSubscription($restaurants['urban'], $plans['enterprise'], $admin);
            $this->ensureSuspendedSubscription($restaurants['royal'], $plans['starter'], $admin);
        });

        $this->command?->info('DEMO-TB-2026 data is present. Existing Super Admin and non-demo records were preserved.');
        $this->command?->line('Owner password was read from LOCAL_DEMO_OWNER_PASSWORD and was not displayed.');
    }

    private function assertSchema(): void
    {
        $required = [
            'restaurants' => ['id', 'name', 'slug', 'email', 'phone', 'address', 'status', 'created_at', 'updated_at'],
            'users' => ['id', 'restaurant_id', 'name', 'email', 'password', 'role', 'status', 'created_at', 'updated_at'],
            'super_admins' => ['id', 'name', 'email', 'password', 'is_active'],
            'subscription_plans' => ['id', 'name', 'code', 'price', 'currency', 'billing_period', 'duration_months', 'duration_days', 'trial_available', 'trial_duration_days', 'status'],
            'restaurant_subscriptions' => ['id', 'restaurant_id', 'subscription_plan_id', 'status', 'starts_at', 'expires_at', 'created_by'],
            'restaurant_subscription_events' => ['id', 'restaurant_id', 'restaurant_subscription_id', 'actor_id', 'action', 'details'],
            'subscription_payments' => ['id', 'restaurant_id', 'subscription_plan_id', 'restaurant_subscription_id', 'amount', 'payment_method', 'transaction_reference', 'status', 'paid_at'],
            'subscription_payment_refunds' => ['id', 'subscription_payment_id', 'admin_id', 'amount', 'method', 'reference', 'refunded_at', 'reason'],
            'restaurant_audit_logs' => ['id', 'actor_id', 'action', 'target_type', 'target_id', 'details'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Required demo schema {$table} is missing or incomplete. No demo data was written.");
            }
        }
    }

    /**
     * @return array<string, SubscriptionPlan>
     */
    private function ensurePlans(SuperAdmin $admin): array
    {
        $definitions = [
            'starter' => ['Starter', '499.00', false, null],
            'basic' => ['Basic', '999.00', true, 14],
            'pro' => ['Pro', '1999.00', false, null],
            'enterprise' => ['Enterprise', '4999.00', false, null],
        ];
        $plans = [];

        foreach ($definitions as $key => [$name, $price, $trialAvailable, $trialDays]) {
            $code = self::MARKER.'-'.Str::upper($key);
            $plan = SubscriptionPlan::query()->where('code', $code)->first();
            if (! $plan) {
                $plan = SubscriptionPlan::create([
                    'name' => $name.' ('.self::MARKER.')',
                    'code' => $code,
                    'description' => 'Local demo plan. Not a production offer.',
                    'price' => $price,
                    'currency' => 'INR',
                    'billing_period' => 'monthly',
                    'duration_months' => 1,
                    'duration_days' => null,
                    'trial_available' => $trialAvailable,
                    'trial_duration_days' => $trialDays,
                    'status' => 'active',
                ]);
                $this->audit($admin, 'subscription_plan_created', 'subscription_plan', $plan->id, [
                    'demo_marker' => self::MARKER,
                    'code' => $code,
                ]);
            } elseif ($plan->currency !== 'INR' || $plan->billing_period !== 'monthly') {
                throw new RuntimeException("Marked demo plan {$code} has incompatible existing terms. No records were changed.");
            }

            $plans[$key] = $plan;
        }

        return $plans;
    }

    /**
     * @return array<string, Restaurant>
     */
    private function ensureRestaurants(string $password, SuperAdmin $admin): array
    {
        $definitions = [
            'central' => ['TastyBite Central', 'demo-tb-2026-central', 'central.owner.demo-tb-2026@example.test', 'Aarav Sen'],
            'bengali' => ['Bengali Kitchen', 'demo-tb-2026-bengali-kitchen', 'bengali.owner.demo-tb-2026@example.test', 'Mira Das'],
            'spice' => ['Spice Garden', 'demo-tb-2026-spice-garden', 'spice.owner.demo-tb-2026@example.test', 'Rohan Patel'],
            'urban' => ['Urban Cafe', 'demo-tb-2026-urban-cafe', 'urban.owner.demo-tb-2026@example.test', 'Anika Roy'],
            'royal' => ['Royal Biryani House', 'demo-tb-2026-royal-biryani', 'royal.owner.demo-tb-2026@example.test', 'Kabir Khan'],
        ];
        $restaurants = [];
        $provisioning = app(RestaurantProvisioningService::class);

        foreach ($definitions as $key => [$name, $slug, $ownerEmail, $ownerName]) {
            $restaurant = Restaurant::query()->where('slug', $slug)->first();
            $owner = User::query()->where('email', $ownerEmail)->first();

            if (! $restaurant && $owner) {
                throw new RuntimeException("Demo owner {$ownerEmail} exists without its marked restaurant. No records were changed.");
            }
            if ($restaurant && $restaurant->name !== $name) {
                throw new RuntimeException("Demo slug {$slug} is already used by a different restaurant. No records were changed.");
            }
            if ($owner && ($owner->restaurant_id !== $restaurant?->id || ! $owner->isOwner())) {
                throw new RuntimeException("Demo owner email {$ownerEmail} is already associated with a different account. No records were changed.");
            }

            if (! $restaurant) {
                $result = $provisioning->provision([
                    'name' => $name,
                    'slug' => $slug,
                    'email' => str_replace('-', '.', $slug).'@example.test',
                    'phone' => '+91-90000-'.str_pad((string) (1000 + count($restaurants)), 4, '0', STR_PAD_LEFT),
                    'address' => 'Local demo address · '.self::MARKER,
                    'status' => 'active',
                ], [
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'status' => 'active',
                ]);
                $restaurant = $result['restaurant'];
                $owner = $result['owner'];
                $owner->forceFill(['password' => Hash::make($password)])->save();

                $this->audit($admin, 'restaurant_created', 'restaurant', $restaurant->id, [
                    'demo_marker' => self::MARKER,
                    'restaurant_name' => $restaurant->name,
                    'owner_email' => $owner->email,
                ]);
                $this->audit($admin, 'owner_created', 'restaurant_user', $owner->id, [
                    'demo_marker' => self::MARKER,
                    'restaurant_id' => $restaurant->id,
                    'email' => $owner->email,
                ]);
            } elseif (! $owner) {
                $owner = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password' => Hash::make($password),
                    'role' => 'owner',
                    'status' => 'active',
                ]);
                $this->audit($admin, 'owner_created', 'restaurant_user', $owner->id, [
                    'demo_marker' => self::MARKER,
                    'restaurant_id' => $restaurant->id,
                    'email' => $owner->email,
                ]);
            }

            $restaurants[$key] = $restaurant;
        }

        return $restaurants;
    }

    private function ensurePaymentWorkflows(array $restaurants, array $plans, SuperAdmin $admin): void
    {
        $service = app(PaymentManagementService::class);
        $now = CarbonImmutable::now(config('app.timezone'));
        $approvedOne = $this->ensurePayment(
            $service,
            $restaurants['central'],
            $plans['starter'],
            $admin,
            self::MARKER.'-PAID-CENTRAL-1',
            '499.00',
            'upi',
            'approved',
            $now,
        );
        $approvedTwo = $this->ensurePayment(
            $service,
            $restaurants['central'],
            $plans['starter'],
            $admin,
            self::MARKER.'-PAID-CENTRAL-2',
            '499.00',
            'bank_transfer',
            'approved',
            $now->addMinute(),
        );
        $this->ensurePayment(
            $service,
            $restaurants['bengali'],
            $plans['basic'],
            $admin,
            self::MARKER.'-PENDING-BENGALI',
            '999.00',
            'upi',
            'pending',
            $now,
        );
        $rejected = $this->ensurePayment(
            $service,
            $restaurants['royal'],
            $plans['starter'],
            $admin,
            self::MARKER.'-REJECTED-ROYAL',
            '499.00',
            'other',
            'rejected',
            $now,
        );
        if ($rejected->status === 'pending') {
            $service->reject($rejected, $admin, 'DEMO DATA ONLY: reference verification declined for this sample.');
        }

        $this->ensureRefund($service, $approvedOne, $admin, self::MARKER.'-PARTIAL-REFUND', '100.00');
        $this->ensureRefund($service, $approvedTwo, $admin, self::MARKER.'-FULL-REFUND', '499.00');
    }

    private function ensurePayment(
        PaymentManagementService $service,
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        SuperAdmin $admin,
        string $reference,
        string $amount,
        string $method,
        string $desiredStatus,
        CarbonImmutable $paidAt,
    ): SubscriptionPayment {
        $payment = SubscriptionPayment::query()
            ->where('payment_method', $method)
            ->where('transaction_reference', $reference)
            ->first();

        if (! $payment) {
            $payment = $service->create([
                'restaurant_id' => $restaurant->id,
                'subscription_plan_id' => $plan->id,
                'amount' => $amount,
                'currency' => 'INR',
                'payment_method' => $method,
                'transaction_reference' => $reference,
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'notes' => 'DEMO DATA ONLY. No money was collected or returned.',
            ], $admin);
        }

        if (
            $payment->restaurant_id !== $restaurant->id
            || $payment->subscription_plan_id !== $plan->id
            || $payment->amount !== $amount
        ) {
            throw new RuntimeException("Marked payment {$reference} has incompatible existing data. No records were changed.");
        }

        if ($desiredStatus === 'approved' && $payment->status === 'pending') {
            $payment = $service->approve($payment, $admin);
        } elseif ($desiredStatus === 'rejected' && $payment->status === 'pending') {
            $payment = $service->reject($payment, $admin, 'DEMO DATA ONLY: reference verification declined for this sample.');
        } elseif ($payment->status !== $desiredStatus && ! ($desiredStatus === 'approved' && $payment->status === 'refunded')) {
            throw new RuntimeException("Marked payment {$reference} has status {$payment->status}; expected {$desiredStatus}. No records were changed.");
        }

        return $payment->fresh();
    }

    private function ensureRefund(
        PaymentManagementService $service,
        SubscriptionPayment $payment,
        SuperAdmin $admin,
        string $reference,
        string $amount,
    ): void {
        if (SubscriptionPaymentRefund::query()->where('method', 'other')->where('reference', $reference)->exists()) {
            return;
        }

        $service->refund($payment, $admin, [
            'amount' => $amount,
            'method' => 'other',
            'reference' => $reference,
            'refunded_at' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s'),
            'reason' => 'DEMO DATA ONLY. This record does not represent a real refund.',
        ]);
    }

    private function ensureTrialSubscription(Restaurant $restaurant, SubscriptionPlan $plan, SuperAdmin $admin): void
    {
        $this->ensureAssignedSubscription($restaurant, $plan, 'demo-trial-bengali', function () use ($restaurant, $plan, $admin): RestaurantSubscription {
            return app(SubscriptionService::class)->assign(
                $restaurant,
                $plan,
                CarbonImmutable::now(config('app.timezone')),
                null,
                true,
                $admin,
                ['demo_marker' => self::MARKER, 'demo_fixture' => 'demo-trial-bengali'],
            );
        });
    }

    private function ensureExpiringSubscription(Restaurant $restaurant, SubscriptionPlan $plan, SuperAdmin $admin): void
    {
        $this->ensureAssignedSubscription($restaurant, $plan, 'demo-expiring-spice', function () use ($restaurant, $plan, $admin): RestaurantSubscription {
            $now = CarbonImmutable::now(config('app.timezone'));

            return app(SubscriptionService::class)->assign(
                $restaurant,
                $plan,
                $now->subDays(25),
                $now->addDays(5),
                false,
                $admin,
                ['demo_marker' => self::MARKER, 'demo_fixture' => 'demo-expiring-spice'],
            );
        });
    }

    private function ensureExpiredSubscription(Restaurant $restaurant, SubscriptionPlan $plan, SuperAdmin $admin): void
    {
        $this->ensureAssignedSubscription($restaurant, $plan, 'demo-expired-urban', function () use ($restaurant, $plan, $admin): RestaurantSubscription {
            $realNow = CarbonImmutable::now(config('app.timezone'));
            $historicalNow = $realNow->subDays(45);
            CarbonImmutable::setTestNow($historicalNow);

            try {
                return app(SubscriptionService::class)->assign(
                    $restaurant,
                    $plan,
                    CarbonImmutable::now(config('app.timezone')),
                    null,
                    false,
                    $admin,
                    ['demo_marker' => self::MARKER, 'demo_fixture' => 'demo-expired-urban'],
                );
            } finally {
                CarbonImmutable::setTestNow();
            }
        });
    }

    private function ensureSuspendedSubscription(Restaurant $restaurant, SubscriptionPlan $plan, SuperAdmin $admin): void
    {
        $subscription = $this->findDemoSubscription('demo-suspended-royal');
        if (! $subscription) {
            $subscription = app(SubscriptionService::class)->assign(
                $restaurant,
                $plan,
                CarbonImmutable::now(config('app.timezone')),
                null,
                false,
                $admin,
                ['demo_marker' => self::MARKER, 'demo_fixture' => 'demo-suspended-royal'],
            );
        }

        if ($subscription->status === 'active') {
            app(SubscriptionService::class)->changeStatus($subscription, 'suspended', $admin, [
                'demo_marker' => self::MARKER,
                'demo_fixture' => 'demo-suspended-royal',
            ]);
        } elseif ($subscription->status !== 'suspended') {
            throw new RuntimeException('Marked demo suspension has an unexpected state. No records were changed.');
        }

        if ($subscription->restaurant_id !== $restaurant->id || $subscription->subscription_plan_id !== $plan->id) {
            throw new RuntimeException('Marked demo suspension points to different records. No records were changed.');
        }
    }

    private function ensureAssignedSubscription(
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        string $fixture,
        callable $create,
    ): void {
        $subscription = $this->findDemoSubscription($fixture);
        if (! $subscription) {
            $subscription = $create();
        }

        if ($subscription->restaurant_id !== $restaurant->id || $subscription->subscription_plan_id !== $plan->id) {
            throw new RuntimeException("Demo subscription fixture {$fixture} points to different records. No records were changed.");
        }
    }

    private function findDemoSubscription(string $fixture): ?RestaurantSubscription
    {
        $legacyFixture = match ($fixture) {
            'demo-trial-bengali' => 'trial',
            'demo-expiring-spice' => 'expiring_soon',
            'demo-expired-urban' => 'expired',
            'demo-suspended-royal' => 'suspended',
            default => $fixture,
        };

        $event = RestaurantSubscriptionEvent::query()
            ->where('details->demo_marker', self::MARKER)
            ->whereIn('details->demo_fixture', [$fixture, $legacyFixture])
            ->whereNotNull('restaurant_subscription_id')
            ->orderByDesc('id')
            ->first();

        return $event ? RestaurantSubscription::query()->find($event->restaurant_subscription_id) : null;
    }

    private function audit(SuperAdmin $admin, string $action, string $targetType, int $targetId, array $details): void
    {
        $existing = RestaurantAuditLog::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('action', $action)
            ->where('details->demo_marker', self::MARKER)
            ->exists();

        if (! $existing) {
            RestaurantAuditLog::create([
                'actor_id' => $admin->id,
                'actor_type' => 'super_admin',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => $details,
            ]);
        }
    }
}
