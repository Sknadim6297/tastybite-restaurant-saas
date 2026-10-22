<?php

namespace App\Services;

use App\Models\PlatformAnnouncement;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\SuperAdminNotification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DemoDataManagementService
{
    public const MARKER = 'DEMO-TB-2026';

    private const RESTAURANT_PREFIX = 'demo-tb-2026-';

    private const PAYMENT_MARKER = 'DEMO DATA ONLY';

    private const REFUND_PAYMENT_NUMBERS = [1, 3, 17, 19, 25, 27, 33, 35];

    private const FULL_REFUND_PAYMENT_NUMBERS = [1, 19, 25, 33];

    private const TARGETS = [
        'restaurants' => 15,
        'owners' => 15,
        'restaurant_subscriptions' => 40,
        'subscription_payments' => 60,
        'subscription_payment_refunds' => 8,
        'subscription_invoices' => 40,
        'restaurant_subscription_events' => 80,
        'support_tickets' => 20,
        'platform_announcements' => 10,
        'super_admin_notifications' => 30,
        'restaurant_audit_logs' => 50,
    ];

    public function preview(): array
    {
        $this->assertSchema();

        $restaurantIds = DB::table('restaurants')
            ->where('slug', 'like', self::RESTAURANT_PREFIX.'%')
            ->pluck('id');
        $paymentIds = DB::table('subscription_payments')
            ->where('notes', 'like', self::PAYMENT_MARKER.'%')
            ->select('id');

        $counts = [
            'restaurants' => $restaurantIds->count(),
            'owners' => DB::table('users')->whereIn('restaurant_id', $restaurantIds)->where('role', 'owner')->count(),
            'subscription_plans' => DB::table('subscription_plans')->where('code', 'like', self::MARKER.'-%')->count(),
            'restaurant_subscriptions' => DB::table('restaurant_subscriptions')->whereIn('restaurant_id', $restaurantIds)->count(),
            'subscription_payments' => DB::table('subscription_payments')->where('notes', 'like', self::PAYMENT_MARKER.'%')->count(),
            'subscription_payment_refunds' => DB::table('subscription_payment_refunds')->whereIn('subscription_payment_id', $paymentIds)->count(),
            'subscription_invoices' => DB::table('subscription_invoices')->whereIn('subscription_payment_id', $paymentIds)->count(),
            'restaurant_subscription_events' => DB::table('restaurant_subscription_events')->where('details->demo_marker', self::MARKER)->count(),
            'support_tickets' => DB::table('support_tickets')->where('ticket_number', 'like', self::MARKER.'-%')->count(),
            'platform_announcements' => DB::table('platform_announcements')->where('title', 'like', '['.self::MARKER.']%')->count(),
            'super_admin_notifications' => DB::table('super_admin_notifications')->where('event_key', 'like', self::MARKER.'-%')->count(),
            'restaurant_audit_logs' => DB::table('restaurant_audit_logs')->where('details->demo_marker', self::MARKER)->count(),
        ];

        $creates = [];
        foreach (self::TARGETS as $table => $target) {
            $creates[$table] = max(0, $target - ($counts[$table] ?? 0));
        }
        $creates['subscription_plans'] = max(0, 5 - (
            (int) DB::table('subscription_plans')->where('code', 'STARTER')->exists()
            + $counts['subscription_plans']
        ));
        $isComplete = collect($creates)->every(fn (int $count): bool => $count === 0);
        $integrityIssues = $isComplete ? $this->completeDatasetIssues() : [];

        return [
            'environment' => app()->environment(),
            'can_generate' => app()->environment('local'),
            'dataset_complete' => $isComplete,
            'integrity_issues' => $integrityIssues,
            'marker' => self::MARKER,
            'counts' => $counts,
            'targets' => self::TARGETS + ['subscription_plans' => 5],
            'would_create' => $creates,
            'payment_statuses' => DB::table('subscription_payments')
                ->where('notes', 'like', self::PAYMENT_MARKER.'%')
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->orderBy('status')
                ->pluck('total', 'status')
                ->all(),
            'subscription_statuses' => RestaurantSubscription::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->get()
                ->countBy(fn (RestaurantSubscription $subscription): string => $subscription->effectiveStatus())
                ->sortKeys()
                ->all(),
        ];
    }

    public function cleanupPreview(): array
    {
        return $this->preview()['counts'] + [
            'subscription_plans' => DB::table('subscription_plans')->where('code', 'like', self::MARKER.'-%')->count(),
        ];
    }

    public function generate(SuperAdmin $admin): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo records can only be generated in the local environment.');
        }
        if (! $admin->is_active) {
            throw new RuntimeException('An active Super Admin is required to generate demo records.');
        }

        $this->assertSchema();
        $preview = $this->preview();
        if (collect($preview['would_create'])->every(fn (int $count): bool => $count === 0)) {
            if ($preview['integrity_issues'] !== []) {
                throw new RuntimeException(implode(' ', $preview['integrity_issues']));
            }

            return $preview;
        }

        DB::transaction(function () use ($admin): void {
            $restaurants = $this->ensureRestaurants();
            $plans = $this->ensurePlans();
            $subscriptions = $this->ensureSubscriptions($restaurants, $plans, $admin);
            $payments = $this->ensurePayments($subscriptions, $admin);

            $this->ensureInvoices($payments, $admin);
            $this->ensureRefunds($payments, $admin);
            $this->ensureSupportTickets($restaurants, $admin);
            $this->ensureAnnouncements($admin);
            $this->ensureNotifications($admin);
            $this->ensureAuditLogs($restaurants, $admin);
        });

        return $this->preview();
    }

    private function completeDatasetIssues(): array
    {
        $restaurantIds = DB::table('restaurants')
            ->where('slug', 'like', self::RESTAURANT_PREFIX.'%')
            ->select('id');
        $paymentIds = DB::table('subscription_payments')
            ->where('notes', 'like', self::PAYMENT_MARKER.'%')
            ->select('id');

        $hasRestaurantWithoutOwner = DB::table('restaurants as r')
            ->leftJoin('users as u', function ($join): void {
                $join->on('u.restaurant_id', '=', 'r.id')->where('u.role', '=', 'owner');
            })
            ->where('r.slug', 'like', self::RESTAURANT_PREFIX.'%')
            ->groupBy('r.id')
            ->havingRaw('COUNT(u.id) = 0')
            ->exists();
        $hasInvalidPayment = DB::table('subscription_payments as p')
            ->leftJoin('restaurant_subscriptions as s', 's.id', '=', 'p.restaurant_subscription_id')
            ->where('p.notes', 'like', self::PAYMENT_MARKER.'%')
            ->where(function ($query): void {
                $query->whereNull('s.id')
                    ->orWhereColumn('s.restaurant_id', '!=', 'p.restaurant_id')
                    ->orWhereColumn('s.subscription_plan_id', '!=', 'p.subscription_plan_id');
            })
            ->exists();
        $hasInvoiceForUnapprovedPayment = DB::table('subscription_invoices as i')
            ->join('subscription_payments as p', 'p.id', '=', 'i.subscription_payment_id')
            ->where('p.notes', 'like', self::PAYMENT_MARKER.'%')
            ->where(function ($query): void {
                $query->whereNotIn('p.status', ['approved', 'refunded'])
                    ->orWhereColumn('i.restaurant_id', '!=', 'p.restaurant_id')
                    ->orWhereColumn('i.subscription_plan_id', '!=', 'p.subscription_plan_id')
                    ->orWhereColumn('i.total', '!=', 'p.amount');
            })
            ->exists();
        $hasExcessRefund = DB::table('subscription_payment_refunds as r')
            ->join('subscription_payments as p', 'p.id', '=', 'r.subscription_payment_id')
            ->where('p.notes', 'like', self::PAYMENT_MARKER.'%')
            ->select('p.id')
            ->groupBy('p.id', 'p.amount')
            ->havingRaw('SUM(r.amount) > p.amount')
            ->exists();
        $hasActiveSubscriptionWithoutApprovedPayment = DB::table('restaurant_subscriptions as s')
            ->whereIn('s.restaurant_id', $restaurantIds)
            ->where('s.status', 'active')
            ->where('s.starts_at', '<=', CarbonImmutable::now(config('app.timezone'))->utc())
            ->where('s.expires_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())
            ->whereNotIn('s.id', DB::table('subscription_payments')
                ->where('notes', 'like', self::PAYMENT_MARKER.'%')
                ->whereIn('status', ['approved', 'refunded'])
                ->select('restaurant_subscription_id'))
            ->exists();
        $hasPendingPaymentOnActiveSubscription = DB::table('subscription_payments as p')
            ->join('restaurant_subscriptions as s', 's.id', '=', 'p.restaurant_subscription_id')
            ->where('p.notes', 'like', self::PAYMENT_MARKER.'%')
            ->whereIn('p.status', ['pending', 'rejected'])
            ->whereIn('s.status', ['active', 'trial'])
            ->where('s.starts_at', '<=', CarbonImmutable::now(config('app.timezone'))->utc())
            ->where('s.expires_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())
            ->exists();
        $expectedStatusCounts = ['approved' => 36, 'pending' => 10, 'refunded' => 4, 'rejected' => 10];
        $actualStatusCounts = DB::table('subscription_payments')
            ->where('notes', 'like', self::PAYMENT_MARKER.'%')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();
        ksort($actualStatusCounts);

        $paymentBeforeSubscriptionStart = DB::table('subscription_payments as p')
            ->join('restaurant_subscriptions as s', 's.id', '=', 'p.restaurant_subscription_id')
            ->where('p.notes', 'like', self::PAYMENT_MARKER.'%')
            ->whereColumn('p.paid_at', '<', 's.starts_at')
            ->count();
        $issues = [];

        if (
            $hasRestaurantWithoutOwner
        ) {
            $issues[] = 'At least one marked demo restaurant has no owner.';
        }
        if ($hasInvalidPayment) {
            $issues[] = 'At least one marked payment does not match its subscription tenant or plan.';
        }
        if ($hasInvoiceForUnapprovedPayment) {
            $issues[] = 'At least one invoice is linked to an unpaid payment or has an amount/tenant mismatch.';
        }
        if ($hasExcessRefund) {
            $issues[] = 'At least one marked payment has refunds exceeding its amount.';
        }
        if ($hasActiveSubscriptionWithoutApprovedPayment) {
            $issues[] = 'At least one currently active paid subscription has no approved or refunded payment.';
        }
        if ($hasPendingPaymentOnActiveSubscription) {
            $issues[] = 'A pending or rejected payment is linked to a currently active subscription.';
        }
        if ($actualStatusCounts !== $expectedStatusCounts) {
            $issues[] = 'The marked payment status totals do not match the demo fixture.';
        }
        if (DB::table('subscription_invoices')->whereIn('subscription_payment_id', $paymentIds)->count() !== 40) {
            $issues[] = 'The marked payment set does not have the expected 40 invoices.';
        }
        if ($paymentBeforeSubscriptionStart > 0) {
            $issues[] = "{$paymentBeforeSubscriptionStart} marked payments are dated before their linked subscription starts.";
        }

        return $issues;
    }

    public function cleanup(SuperAdmin $admin): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo records can only be removed in the local environment.');
        }
        if (! $admin->is_active) {
            throw new RuntimeException('An active Super Admin is required to remove demo records.');
        }

        $this->assertSchema();
        $before = $this->cleanupPreview();

        DB::transaction(function (): void {
            $restaurantIds = DB::table('restaurants')
                ->where('slug', 'like', self::RESTAURANT_PREFIX.'%')
                ->pluck('id');
            $paymentIds = DB::table('subscription_payments')
                ->where('notes', 'like', self::PAYMENT_MARKER.'%')
                ->pluck('id');
            $subscriptionIds = DB::table('restaurant_subscriptions')
                ->whereIn('restaurant_id', $restaurantIds)
                ->pluck('id');
            $ticketIds = DB::table('support_tickets')
                ->where('ticket_number', 'like', self::MARKER.'-%')
                ->pluck('id');
            $announcementIds = DB::table('platform_announcements')
                ->where('title', 'like', '['.self::MARKER.']%')
                ->pluck('id');
            $demoPlanIds = DB::table('subscription_plans')
                ->where('code', 'like', self::MARKER.'-%')
                ->pluck('id');

            $this->assertCleanupSafe($restaurantIds, $paymentIds, $subscriptionIds, $ticketIds, $announcementIds, $demoPlanIds);

            if (Schema::hasTable('restaurant_support_ticket_notifications')) {
                DB::table('restaurant_support_ticket_notifications')->whereIn('support_ticket_id', $ticketIds)->delete();
            }
            DB::table('support_ticket_messages')->whereIn('support_ticket_id', $ticketIds)->delete();
            DB::table('support_ticket_status_history')->whereIn('support_ticket_id', $ticketIds)->delete();
            DB::table('support_tickets')->whereIn('id', $ticketIds)->delete();

            if (Schema::hasTable('platform_announcement_restaurants')) {
                DB::table('platform_announcement_restaurants')->whereIn('platform_announcement_id', $announcementIds)->delete();
            }
            if (Schema::hasTable('restaurant_announcement_reads')) {
                DB::table('restaurant_announcement_reads')->whereIn('platform_announcement_id', $announcementIds)->delete();
            }
            DB::table('platform_announcements')->whereIn('id', $announcementIds)->delete();
            DB::table('super_admin_notifications')->where('event_key', 'like', self::MARKER.'-%')->delete();

            DB::table('restaurant_audit_logs')->where('details->demo_marker', self::MARKER)->delete();
            DB::table('restaurant_subscription_events')->where('details->demo_marker', self::MARKER)->delete();
            DB::table('subscription_payment_refunds')->whereIn('subscription_payment_id', $paymentIds)->delete();
            DB::table('subscription_invoices')->whereIn('subscription_payment_id', $paymentIds)->delete();
            DB::table('subscription_payments')->whereIn('id', $paymentIds)->delete();
            DB::table('restaurant_subscriptions')->whereIn('id', $subscriptionIds)->delete();
            DB::table('users')->whereIn('restaurant_id', $restaurantIds)->delete();
            DB::table('restaurants')->whereIn('id', $restaurantIds)->delete();
            DB::table('subscription_plans')->whereIn('id', $demoPlanIds)->delete();
        });

        return $before;
    }

    private function assertCleanupSafe(
        $restaurantIds,
        $paymentIds,
        $subscriptionIds,
        $ticketIds,
        $announcementIds,
        $demoPlanIds,
    ): void {
        if (DB::table('subscription_payments')->whereIn('restaurant_id', $restaurantIds)
            ->where(function ($query): void {
                $query->whereNull('notes')->orWhere('notes', 'not like', self::PAYMENT_MARKER.'%');
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has an unmarked payment.');
        }
        if (DB::table('subscription_payments')->where('notes', 'like', self::PAYMENT_MARKER.'%')
            ->whereNotIn('restaurant_id', $restaurantIds)->exists()) {
            throw new RuntimeException('Cleanup stopped because a marked demo payment belongs to a non-demo restaurant.');
        }

        if (DB::table('subscription_payment_refunds')->whereIn('subscription_payment_id', $paymentIds)
            ->where(function ($query): void {
                $query->whereNull('reason')->orWhere('reason', 'not like', self::PAYMENT_MARKER.'%');
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo payment has an unmarked refund.');
        }
        if (DB::table('subscription_invoices')->whereIn('restaurant_id', $restaurantIds)
            ->whereNotIn('subscription_payment_id', $paymentIds)->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has an invoice linked to a non-demo payment.');
        }

        if (DB::table('restaurant_subscription_events')->whereIn('restaurant_subscription_id', $subscriptionIds)
            ->where(function ($query): void {
                $query->whereNull('details->demo_marker')->orWhere('details->demo_marker', '!=', self::MARKER);
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because demo subscription history contains unmarked events.');
        }
        if (DB::table('restaurant_subscription_events')->whereIn('restaurant_id', $restaurantIds)
            ->where(function ($query): void {
                $query->whereNull('details->demo_marker')->orWhere('details->demo_marker', '!=', self::MARKER);
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has unmarked subscription history.');
        }

        $markedSubscriptionIds = DB::table('restaurant_subscription_events')
            ->where('details->demo_marker', self::MARKER)
            ->whereIn('restaurant_subscription_id', $subscriptionIds)
            ->distinct()
            ->pluck('restaurant_subscription_id');
        if ($subscriptionIds->diff($markedSubscriptionIds)->isNotEmpty()) {
            throw new RuntimeException('Cleanup stopped because a demo subscription has no demo history marker.');
        }

        if (DB::table('support_tickets')->whereIn('restaurant_id', $restaurantIds)
            ->whereNotIn('id', $ticketIds)->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has a non-demo support ticket.');
        }
        if (DB::table('support_ticket_messages')->whereIn('support_ticket_id', $ticketIds)
            ->where(function ($query): void {
                $query->whereNull('body')->orWhere('body', 'not like', self::PAYMENT_MARKER.'%');
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo ticket has an unmarked message.');
        }
        if (DB::table('support_ticket_status_history')->whereIn('support_ticket_id', $ticketIds)
            ->where(function ($query): void {
                $query->whereNull('reason')->orWhere('reason', 'not like', self::PAYMENT_MARKER.'%');
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo ticket has unmarked status history.');
        }
        if (DB::table('platform_announcements')->whereIn('id', $announcementIds)->where('status', '!=', 'draft')->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo announcement has been published or archived.');
        }
        if (DB::table('restaurant_audit_logs')->whereIn('restaurant_id', $restaurantIds)
            ->where(function ($query): void {
                $query->whereNull('details->demo_marker')->orWhere('details->demo_marker', '!=', self::MARKER);
            })->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has unmarked audit history.');
        }
        if (DB::table('restaurant_subscriptions')->whereIn('subscription_plan_id', $demoPlanIds)
            ->whereNotIn('restaurant_id', $restaurantIds)->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo plan is attached to a non-demo restaurant.');
        }
        if (DB::table('users')->whereIn('restaurant_id', $restaurantIds)
            ->where('email', 'not like', '%demo-tb-2026%')->exists()) {
            throw new RuntimeException('Cleanup stopped because a demo restaurant has an unmarked user account.');
        }

        foreach (['platform_announcement_restaurants', 'restaurant_announcement_reads'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->whereIn('restaurant_id', $restaurantIds)
                ->whereNotIn('platform_announcement_id', $announcementIds)->exists()) {
                throw new RuntimeException('Cleanup stopped because a demo restaurant is linked to a non-demo announcement.');
            }
        }

        $handledRestaurantForeignKeys = [
            'platform_announcement_restaurants',
            'restaurant_audit_logs',
            'restaurant_announcement_reads',
            'restaurant_subscription_events',
            'restaurant_subscriptions',
            'subscription_invoices',
            'subscription_payments',
            'support_tickets',
            'users',
        ];
        foreach (Schema::getTableListing() as $table) {
            $tableName = Str::afterLast($table, '.');
            if ($tableName === 'restaurants' || in_array($tableName, $handledRestaurantForeignKeys, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== 'restaurants') {
                    continue;
                }
                $column = $foreignKey['columns'][0] ?? null;
                if ($column && DB::table($tableName)->whereIn($column, $restaurantIds)->exists()) {
                    throw new RuntimeException("Cleanup stopped because {$tableName} contains related data that is not part of the demo fixture.");
                }
            }
        }

        foreach (Schema::getTableListing() as $table) {
            $tableName = Str::afterLast($table, '.');
            if (in_array($tableName, [
                'restaurant_subscription_events',
                'restaurant_subscriptions',
                'subscription_invoices',
                'subscription_payments',
            ], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== 'subscription_plans') {
                    continue;
                }
                $column = $foreignKey['columns'][0] ?? null;
                if ($column && DB::table($tableName)->whereIn($column, $demoPlanIds)->exists()) {
                    throw new RuntimeException("Cleanup stopped because {$tableName} contains related demo-plan data.");
                }
            }
        }
    }

    private function assertSchema(): void
    {
        $required = [
            'restaurants' => ['id', 'name', 'slug', 'email', 'phone', 'address', 'status', 'approval_status', 'created_at', 'updated_at'],
            'users' => ['id', 'restaurant_id', 'name', 'email', 'password', 'role', 'status', 'created_at', 'updated_at'],
            'subscription_plans' => ['id', 'name', 'code', 'price', 'currency', 'billing_period', 'duration_months', 'duration_days', 'trial_available', 'trial_duration_days', 'status'],
            'restaurant_subscriptions' => ['id', 'restaurant_id', 'subscription_plan_id', 'plan_name', 'plan_code', 'price', 'currency', 'billing_period', 'duration_months', 'duration_days', 'status', 'is_trial', 'starts_at', 'expires_at', 'created_by'],
            'restaurant_subscription_events' => ['id', 'restaurant_id', 'restaurant_subscription_id', 'subscription_plan_id', 'actor_id', 'action', 'details'],
            'subscription_payments' => ['id', 'restaurant_id', 'subscription_plan_id', 'created_by', 'approved_by', 'rejected_by', 'restaurant_subscription_id', 'plan_name', 'plan_code', 'amount', 'currency', 'payment_method', 'transaction_reference', 'status', 'paid_at', 'notes', 'approved_at', 'rejected_at', 'rejection_reason'],
            'subscription_payment_refunds' => ['id', 'subscription_payment_id', 'admin_id', 'amount', 'method', 'reference', 'refunded_at', 'reason'],
            'subscription_invoices' => ['id', 'subscription_payment_id', 'restaurant_id', 'subscription_plan_id', 'restaurant_subscription_id', 'created_by', 'invoice_number', 'document_type', 'subtotal', 'tax_amount', 'total', 'issue_date', 'billing_period_start', 'billing_period_end'],
            'platform_announcements' => ['id', 'created_by', 'title', 'body', 'audience', 'status', 'published_at', 'expires_at'],
            'support_tickets' => ['id', 'ticket_number', 'restaurant_id', 'created_by_user_id', 'assigned_admin_id', 'subject', 'category', 'priority', 'status', 'last_activity_at'],
            'support_ticket_messages' => ['id', 'support_ticket_id', 'author_user_id', 'author_super_admin_id', 'visibility', 'body'],
            'support_ticket_status_history' => ['id', 'support_ticket_id', 'actor_user_id', 'actor_super_admin_id', 'previous_status', 'new_status', 'reason'],
            'super_admin_notifications' => ['id', 'super_admin_id', 'event_key', 'category', 'title', 'description', 'url', 'read_at'],
            'restaurant_audit_logs' => ['id', 'actor_id', 'actor_type', 'restaurant_id', 'action', 'target_type', 'target_id', 'details'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Required demo schema {$table} is missing or incomplete.");
            }
        }
    }

    private function ensureRestaurants(): array
    {
        $definitions = [
            ['TastyBite Central', 'central', 'central.owner.demo-tb-2026@example.test', 'Aarav Sen'],
            ['Bengali Kitchen', 'bengali-kitchen', 'bengali.owner.demo-tb-2026@example.test', 'Mira Das'],
            ['Spice Garden', 'spice-garden', 'spice.owner.demo-tb-2026@example.test', 'Rohan Patel'],
            ['Urban Cafe', 'urban-cafe', 'urban.owner.demo-tb-2026@example.test', 'Anika Roy'],
            ['Royal Biryani House', 'royal-biryani', 'royal.owner.demo-tb-2026@example.test', 'Kabir Khan'],
            ['Coastal Curry House', 'coastal-curry', null, 'Ishita Nair'],
            ['Punjab Plate', 'punjab-plate', null, 'Arjun Gill'],
            ['Dosa Junction', 'dosa-junction', null, 'Kavya Iyer'],
            ['The Saffron Table', 'saffron-table', null, 'Vihaan Mehta'],
            ['Chai & Crumb', 'chai-crumb', null, 'Aditi Rao'],
            ['Wok Street Kitchen', 'wok-street', null, 'Dev Khanna'],
            ['Green Leaf Bistro', 'green-leaf', null, 'Meera Joshi'],
            ['Sweet Coast Desserts', 'sweet-coast', null, 'Neel Shah'],
            ['Nawab Grill Room', 'nawab-grill', null, 'Sana Qureshi'],
            ['Market Square Cafe', 'market-square', null, 'Reyansh Das'],
        ];
        $restaurants = [];

        foreach ($definitions as $index => [$name, $key, $legacyEmail, $ownerName]) {
            $slug = self::RESTAURANT_PREFIX.$key;
            $email = $legacyEmail ?? sprintf('owner.%02d@demo-tb-2026.example.test', $index + 1);
            $restaurant = Restaurant::query()->where('slug', $slug)->first();

            if ($restaurant && $restaurant->name !== $name) {
                throw new RuntimeException("Demo restaurant slug {$slug} belongs to a different record; no data was changed.");
            }

            if (! $restaurant) {
                $restaurant = Restaurant::create([
                    'name' => $name,
                    'slug' => $slug,
                    'email' => sprintf('restaurant.%02d@demo-tb-2026.example.test', $index + 1),
                    'phone' => '+91-90000-'.str_pad((string) (2000 + $index), 4, '0', STR_PAD_LEFT),
                    'address' => 'Sample address for local demo data.',
                    'status' => 'active',
                    'approval_status' => 'approved',
                ]);
            }

            $owner = User::query()->where('email', $email)->first();
            if ($owner && ($owner->restaurant_id !== $restaurant->id || ! $owner->isOwner())) {
                throw new RuntimeException("Demo owner email {$email} belongs to a different record; no data was changed.");
            }
            if (! $owner) {
                $owner = new User([
                    'restaurant_id' => $restaurant->id,
                    'name' => $ownerName,
                    'email' => $email,
                    'role' => 'owner',
                    'status' => 'active',
                ]);
                $owner->password = Str::random(64);
                $owner->save();
            }

            $restaurants[] = $restaurant;
        }

        return $restaurants;
    }

    private function ensurePlans(): array
    {
        $starter = SubscriptionPlan::query()->where('code', 'STARTER')->first();
        if (! $starter) {
            $starter = $this->ensurePlan('STARTER', [
                'name' => 'Starter Monthly',
                'price' => '499.00',
                'billing_period' => 'monthly',
                'duration_months' => 1,
                'duration_days' => null,
                'trial_available' => false,
                'trial_duration_days' => null,
            ]);
        }

        if (! $starter->isActive()) {
            throw new RuntimeException('The existing Starter plan is inactive; it was not modified.');
        }

        return [
            $starter,
            $this->ensurePlan('GROWTH', [
                'name' => 'Growth Monthly',
                'price' => '999.00',
                'billing_period' => 'monthly',
                'duration_months' => 1,
                'duration_days' => null,
                'trial_available' => true,
                'trial_duration_days' => 14,
            ]),
            $this->ensurePlan('PROFESSIONAL', [
                'name' => 'Professional Monthly',
                'price' => '1999.00',
                'billing_period' => 'monthly',
                'duration_months' => 1,
                'duration_days' => null,
                'trial_available' => false,
                'trial_duration_days' => null,
            ]),
            $this->ensurePlan('BUSINESS-QUARTERLY', [
                'name' => 'Business Quarterly',
                'price' => '4999.00',
                'billing_period' => 'quarterly',
                'duration_months' => 3,
                'duration_days' => null,
                'trial_available' => false,
                'trial_duration_days' => null,
            ]),
            $this->ensurePlan('ENTERPRISE-YEARLY', [
                'name' => 'Enterprise Yearly',
                'price' => '14999.00',
                'billing_period' => 'yearly',
                'duration_months' => 12,
                'duration_days' => null,
                'trial_available' => false,
                'trial_duration_days' => null,
            ]),
        ];
    }

    private function ensurePlan(string $suffix, array $terms): SubscriptionPlan
    {
        $code = self::MARKER.'-'.$suffix;
        $plan = SubscriptionPlan::query()->where('code', $code)->first();
        if ($plan) {
            foreach ($terms as $field => $value) {
                if ((string) $plan->{$field} !== (string) $value) {
                    throw new RuntimeException("Marked demo plan {$code} has different terms; it was not modified.");
                }
            }

            return $plan;
        }

        return SubscriptionPlan::create($terms + [
            'code' => $code,
            'description' => 'Synthetic local testing plan. Not a production offer.',
            'currency' => 'INR',
            'status' => 'active',
        ]);
    }

    private function ensureSubscriptions(array $restaurants, array $plans, SuperAdmin $admin): array
    {
        $statuses = array_merge(
            array_fill(0, 8, 'active'),
            array_fill(0, 12, 'expired'),
            array_fill(0, 5, 'pending_payment'),
            array_fill(0, 5, 'pending_approval'),
            array_fill(0, 2, 'scheduled'),
            array_fill(0, 2, 'trial'),
            array_fill(0, 3, 'suspended'),
            array_fill(0, 3, 'cancelled'),
        );
        $now = CarbonImmutable::now(config('app.timezone'));
        $subscriptions = [];

        foreach ($statuses as $zeroIndex => $desiredStatus) {
            $fixture = sprintf('subscription-%03d', $zeroIndex + 1);
            $event = RestaurantSubscriptionEvent::query()
                ->where('details->demo_marker', self::MARKER)
                ->where('details->demo_fixture', $fixture)
                ->whereNotNull('restaurant_subscription_id')
                ->first();
            $subscription = $event
                ? RestaurantSubscription::query()->find($event->restaurant_subscription_id)
                : null;

            if ($event && ! $subscription) {
                throw new RuntimeException("Demo subscription {$fixture} has a broken history relationship.");
            }

            if (! $subscription) {
                $plan = match ($desiredStatus) {
                    'trial' => $plans[1],
                    'expired' => $plans[0],
                    default => $plans[$zeroIndex % count($plans)],
                };
                $start = match ($desiredStatus) {
                    'scheduled' => $now->addDays(7)->startOfDay(),
                    'trial' => $now->subDays(2)->startOfDay(),
                    'expired' => $now->startOfMonth()->subMonths(min(11, $zeroIndex - 7)),
                    'pending_payment', 'pending_approval' => $now->startOfDay(),
                    default => $now->subDays(15)->startOfDay(),
                };
                $expires = match ($desiredStatus) {
                    'trial' => $start->addDays((int) $plan->trial_duration_days),
                    'expired' => $now->subDays(1)->startOfDay(),
                    default => $this->expiry($plan, $start),
                };
                $storedStatus = in_array($desiredStatus, ['scheduled', 'expired'], true) ? 'active' : $desiredStatus;
                $subscription = RestaurantSubscription::create([
                    'restaurant_id' => $restaurants[$zeroIndex % count($restaurants)]->id,
                    'subscription_plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'plan_code' => $plan->code,
                    'price' => $plan->price,
                    'currency' => $plan->currency,
                    'billing_period' => $plan->billing_period,
                    'duration_months' => $plan->duration_months,
                    'duration_days' => $plan->duration_days,
                    'status' => $storedStatus,
                    'is_trial' => $desiredStatus === 'trial',
                    'starts_at' => $start->utc(),
                    'expires_at' => $expires->utc(),
                    'created_by' => $admin->id,
                ]);
                $details = ['demo_marker' => self::MARKER, 'demo_fixture' => $fixture];

                RestaurantSubscriptionEvent::create([
                    'restaurant_id' => $subscription->restaurant_id,
                    'restaurant_subscription_id' => $subscription->id,
                    'subscription_plan_id' => $plan->id,
                    'actor_id' => $admin->id,
                    'action' => 'assigned',
                    'new_status' => $storedStatus,
                    'starts_at' => $subscription->starts_at,
                    'expires_at' => $subscription->expires_at,
                    'details' => $details + ['demo_event' => 'assigned'],
                ]);
                RestaurantSubscriptionEvent::create([
                    'restaurant_id' => $subscription->restaurant_id,
                    'restaurant_subscription_id' => $subscription->id,
                    'subscription_plan_id' => $plan->id,
                    'actor_id' => $admin->id,
                    'action' => 'demo_status_recorded',
                    'previous_status' => $desiredStatus === 'active' ? 'pending_approval' : null,
                    'new_status' => $storedStatus,
                    'starts_at' => $subscription->starts_at,
                    'expires_at' => $subscription->expires_at,
                    'details' => $details + ['demo_event' => 'status_recorded'],
                ]);
            } elseif (
                $subscription->restaurant_id !== $restaurants[$zeroIndex % count($restaurants)]->id
                || (
                    in_array($desiredStatus, ['scheduled', 'expired'], true)
                        ? $subscription->effectiveStatus() !== $desiredStatus
                        : $subscription->status !== $desiredStatus
                )
            ) {
                throw new RuntimeException("Marked demo subscription {$fixture} no longer matches its fixture; it was not modified.");
            }

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    private function expiry(SubscriptionPlan $plan, CarbonImmutable $start): CarbonImmutable
    {
        return $plan->duration_months
            ? $start->addMonthsNoOverflow((int) $plan->duration_months)
            : $start->addDays((int) ($plan->duration_days ?? 30));
    }

    private function ensurePayments(array $subscriptions, SuperAdmin $admin): array
    {
        $payments = [];
        $methods = ['upi', 'bank_transfer', 'cash', 'other'];
        $fixtureNumber = 1;

        foreach (array_slice($subscriptions, 0, 20) as $index => $subscription) {
            $halfCents = intdiv(SubscriptionPayment::toCents((string) $subscription->price), 2);
            $subscriptionStart = CarbonImmutable::instance($subscription->starts_at)
                ->timezone(config('app.timezone'))
                ->startOfDay();

            for ($installment = 1; $installment <= 2; $installment++) {
                $reference = $this->transactionReference($methods[($fixtureNumber - 1) % count($methods)], $fixtureNumber);
                $fullRefund = in_array($fixtureNumber, self::FULL_REFUND_PAYMENT_NUMBERS, true);
                $payment = $this->ensurePayment(
                    $subscription,
                    $admin,
                    $reference,
                    SubscriptionPayment::formatCents($halfCents),
                    $methods[($fixtureNumber - 1) % count($methods)],
                    $fullRefund ? 'refunded' : 'approved',
                    $subscriptionStart->addDays($installment * 5),
                );
                $payments[$fixtureNumber] = $payment;
                $fixtureNumber++;
            }
        }

        foreach (array_slice($subscriptions, 20, 10) as $index => $subscription) {
            $status = $index < 5 ? 'rejected' : 'pending';
            $reference = $this->transactionReference($methods[($fixtureNumber - 1) % count($methods)], $fixtureNumber);
            $payment = $this->ensurePayment(
                $subscription,
                $admin,
                $reference,
                (string) $subscription->price,
                $methods[($fixtureNumber - 1) % count($methods)],
                $status,
                CarbonImmutable::instance($subscription->starts_at),
            );
            $payments[$fixtureNumber] = $payment;
            $fixtureNumber++;

            $secondReference = $this->transactionReference($methods[($fixtureNumber - 1) % count($methods)], $fixtureNumber);
            $second = $this->ensurePayment(
                $subscription,
                $admin,
                $secondReference,
                (string) $subscription->price,
                $methods[($fixtureNumber - 1) % count($methods)],
                $status,
                CarbonImmutable::instance($subscription->starts_at),
            );
            $payments[$fixtureNumber] = $second;
            $fixtureNumber++;
        }

        return $payments;
    }

    private function transactionReference(string $method, int $number): string
    {
        return sprintf('DEMO-%s-%04d', strtoupper(str_replace('_', '-', $method)), $number);
    }

    private function ensurePayment(
        RestaurantSubscription $subscription,
        SuperAdmin $admin,
        string $reference,
        string $amount,
        string $method,
        string $status,
        CarbonImmutable $paidAt,
    ): SubscriptionPayment {
        $payment = SubscriptionPayment::query()
            ->where('payment_method', $method)
            ->where('transaction_reference', $reference)
            ->first();

        if ($payment) {
            if (
                ! $payment->isDemo()
                || $payment->restaurant_id !== $subscription->restaurant_id
                || $payment->restaurant_subscription_id !== $subscription->id
                || $payment->amount !== $amount
                || $payment->status !== $status
            ) {
                throw new RuntimeException("Marked payment {$reference} differs from its fixture; it was not modified.");
            }

            return $payment;
        }

        $payment = SubscriptionPayment::create([
            'restaurant_id' => $subscription->restaurant_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'restaurant_subscription_id' => $subscription->id,
            'created_by' => $admin->id,
            'approved_by' => in_array($status, ['approved', 'refunded'], true) ? $admin->id : null,
            'rejected_by' => $status === 'rejected' ? $admin->id : null,
            'plan_name' => $subscription->plan_name,
            'plan_code' => $subscription->plan_code,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'payment_method' => $method,
            'transaction_reference' => $reference,
            'status' => $status,
            'paid_at' => $paidAt->utc(),
            'notes' => self::PAYMENT_MARKER.'. '.self::MARKER.'. Synthetic local financial test record; no money was collected or returned.',
            'approved_at' => in_array($status, ['approved', 'refunded'], true) ? $paidAt->utc() : null,
            'rejected_at' => $status === 'rejected' ? $paidAt->utc() : null,
            'rejection_reason' => $status === 'rejected' ? self::PAYMENT_MARKER.': synthetic declined test payment.' : null,
        ]);

        return $payment;
    }

    private function ensureInvoices(array $payments, SuperAdmin $admin): void
    {
        foreach ($payments as $payment) {
            if (! in_array($payment->status, ['approved', 'refunded'], true)) {
                continue;
            }

            if ($payment->invoice()->exists()) {
                continue;
            }

            $subscription = $payment->subscription;
            $date = CarbonImmutable::instance($payment->paid_at);
            SubscriptionInvoice::create([
                'subscription_payment_id' => $payment->id,
                'restaurant_id' => $payment->restaurant_id,
                'subscription_plan_id' => $payment->subscription_plan_id,
                'restaurant_subscription_id' => $subscription?->id,
                'created_by' => $admin->id,
                'invoice_number' => sprintf('TB-RCT-%s-%08d', $date->timezone(config('app.timezone'))->format('Ym'), $payment->id),
                'document_type' => 'non_tax_payment_receipt',
                'subtotal' => $payment->amount,
                'tax_amount' => '0.00',
                'total' => $payment->amount,
                'issue_date' => $date->utc(),
                'billing_period_start' => $subscription?->starts_at,
                'billing_period_end' => $subscription?->expires_at,
            ]);
        }
    }

    private function ensureRefunds(array $payments, SuperAdmin $admin): void
    {
        foreach (self::REFUND_PAYMENT_NUMBERS as $index => $paymentNumber) {
            $refundNumber = $index + 1;
            $payment = $payments[$paymentNumber];
            $reference = sprintf(self::MARKER.'-REFUND-%04d', $refundNumber);
            $existing = SubscriptionPaymentRefund::query()->where('method', 'other')->where('reference', $reference)->first();
            if ($existing) {
                if ($existing->subscription_payment_id !== $payment->id) {
                    throw new RuntimeException("Marked refund {$reference} points to a different payment.");
                }

                continue;
            }

            $fullRefund = in_array($paymentNumber, self::FULL_REFUND_PAYMENT_NUMBERS, true);
            $amount = $fullRefund
                ? (string) $payment->amount
                : SubscriptionPayment::formatCents(min(5000, SubscriptionPayment::toCents((string) $payment->amount)));
            SubscriptionPaymentRefund::create([
                'subscription_payment_id' => $payment->id,
                'admin_id' => $admin->id,
                'amount' => $amount,
                'method' => 'other',
                'reference' => $reference,
                'refunded_at' => CarbonImmutable::instance($payment->paid_at)->addDays($refundNumber + 1)->utc(),
                'reason' => self::PAYMENT_MARKER.'. '.self::MARKER.'. Synthetic accounting test only; no funds were returned.',
            ]);
        }
    }

    private function ensureSupportTickets(array $restaurants, SuperAdmin $admin): void
    {
        $statuses = ['open', 'in_progress', 'waiting_for_restaurant', 'resolved', 'closed'];
        foreach (range(1, 20) as $number) {
            $ticketNumber = sprintf(self::MARKER.'-%05d', $number);
            $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->first();
            if ($ticket) {
                continue;
            }

            $restaurant = $restaurants[($number - 1) % count($restaurants)];
            $owner = $restaurant->owners()->firstOrFail();
            $ticket = SupportTicket::create([
                'ticket_number' => $ticketNumber,
                'restaurant_id' => $restaurant->id,
                'created_by_user_id' => $owner->id,
                'assigned_admin_id' => $admin->id,
                'subject' => self::MARKER.': sample '.['billing', 'menu', 'access', 'reports', 'settings'][($number - 1) % 5].' question '.$number,
                'category' => ['billing', 'technical', 'account', 'feature_request', 'other'][($number - 1) % 5],
                'priority' => ['low', 'normal', 'high', 'urgent'][($number - 1) % 4],
                'status' => $statuses[($number - 1) % count($statuses)],
                'last_activity_at' => now()->subDays($number),
            ]);
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'author_user_id' => $owner->id,
                'visibility' => 'public',
                'body' => self::PAYMENT_MARKER.'. This is a synthetic support conversation for local testing.',
            ]);
            SupportTicketStatusHistory::create([
                'support_ticket_id' => $ticket->id,
                'actor_super_admin_id' => $admin->id,
                'new_status' => $ticket->status,
                'reason' => self::PAYMENT_MARKER.'. '.self::MARKER.'.',
                'created_at' => $ticket->last_activity_at,
            ]);
        }
    }

    private function ensureAnnouncements(SuperAdmin $admin): void
    {
        foreach (range(1, 10) as $number) {
            $title = sprintf('[%s] Sample announcement %02d', self::MARKER, $number);
            if (PlatformAnnouncement::query()->where('title', $title)->exists()) {
                continue;
            }

            PlatformAnnouncement::create([
                'created_by' => $admin->id,
                'title' => $title,
                'body' => 'Synthetic draft announcement for testing the announcement module. Do not publish or send externally.',
                'audience' => 'all',
                'status' => 'draft',
            ]);
        }
    }

    private function ensureNotifications(SuperAdmin $admin): void
    {
        foreach (range(1, 30) as $number) {
            $eventKey = sprintf(self::MARKER.'-notification-%03d', $number);
            if (SuperAdminNotification::query()->where('super_admin_id', $admin->id)->where('event_key', $eventKey)->exists()) {
                continue;
            }

            SuperAdminNotification::create([
                'super_admin_id' => $admin->id,
                'event_key' => $eventKey,
                'category' => 'demo_data',
                'title' => self::MARKER.': sample notification '.$number,
                'description' => 'Synthetic notification for local Super Admin testing.',
                'url' => route('super-admin.reports.index'),
                'read_at' => $number % 2 === 0 ? now()->subDays($number) : null,
            ]);
        }
    }

    private function ensureAuditLogs(array $restaurants, SuperAdmin $admin): void
    {
        foreach (range(1, 50) as $number) {
            $exists = RestaurantAuditLog::query()
                ->where('action', 'demo_record_created')
                ->where('target_type', 'demo_data')
                ->where('details->demo_marker', self::MARKER)
                ->where('details->demo_fixture', sprintf('audit-%03d', $number))
                ->exists();
            if ($exists) {
                continue;
            }

            $restaurant = $restaurants[($number - 1) % count($restaurants)];
            RestaurantAuditLog::create([
                'actor_id' => $admin->id,
                'actor_type' => 'super_admin',
                'restaurant_id' => $restaurant->id,
                'action' => 'demo_record_created',
                'target_type' => 'demo_data',
                'target_id' => $restaurant->id,
                'details' => [
                    'demo_marker' => self::MARKER,
                    'demo_fixture' => sprintf('audit-%03d', $number),
                    'restaurant_id' => $restaurant->id,
                ],
            ]);
        }
    }
}
