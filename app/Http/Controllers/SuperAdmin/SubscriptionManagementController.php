<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\PaymentManagementService;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubscriptionManagementController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentManagementService $payments,
    ) {}

    public function index(Request $request): View
    {
        return $this->listing($request, false);
    }

    public function expiring(Request $request): View
    {
        return $this->listing($request, true);
    }

    public function create(): View
    {
        return view('super-admin.subscriptions.create', [
            'restaurants' => Restaurant::query()->where('status', 'active')->where('slug', 'not like', 'demo-tb-2026-%')->orderBy('name')->get(['id', 'name', 'slug']),
            'plans' => SubscriptionPlan::query()->where('status', 'active')->where('code', 'not like', 'DEMO-TB-2026-%')->orderBy('name')->get(),
            'startDate' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d'),
            'paymentPreferences' => app(\App\Services\PlatformSettingsService::class)->group('payment'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'trial' => ['sometimes', 'boolean'],
        ]);

        $actor = $this->actor();
        $restaurant = Restaurant::query()->where('slug', 'not like', 'demo-tb-2026-%')->findOrFail($validated['restaurant_id']);
        if (! $restaurant->isActive()) {
            throw ValidationException::withMessages(['restaurant_id' => 'Only an active restaurant can receive a subscription.']);
        }
        $plan = SubscriptionPlan::query()->where('code', 'not like', 'DEMO-TB-2026-%')->findOrFail($validated['subscription_plan_id']);
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $validated['starts_at'], config('app.timezone'));

        $subscription = $this->subscriptions->assign(
            $restaurant,
            $plan,
            $startsAt,
            (bool) ($validated['trial'] ?? false),
            $actor,
        );

        return redirect()->route('super-admin.subscriptions.show', $subscription)
            ->with('status', 'Restaurant subscription assigned successfully.');
    }

    public function show(RestaurantSubscription $restaurantSubscription): View
    {
        $this->ensureBusinessSubscription($restaurantSubscription);
        $restaurantSubscription->load([
            'restaurant',
            'plan',
            'creator',
            'payments' => function ($payments): void {
                $this->realPayments($payments->getQuery());
                $payments->with(['creator', 'approver', 'rejector', 'invoice', 'refunds'])->latest('paid_at');
            },
            'events' => fn ($query) => $query->with('actor')->latest(),
        ]);

        return view('super-admin.subscriptions.show', ['subscription' => $restaurantSubscription]);
    }

    public function renew(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $this->ensureBusinessSubscription($restaurantSubscription);
        $renewal = $this->subscriptions->renew($restaurantSubscription, $this->actor());

        return redirect()->route('super-admin.subscriptions.show', $renewal)
            ->with('status', 'Renewal created and awaiting payment. The previous subscription remains in history.');
    }

    public function extend(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $this->ensureBusinessSubscription($restaurantSubscription);
        $validated = $request->validate([
            'expiry_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $validated['expiry_date'], config('app.timezone'));

        $this->subscriptions->extend($restaurantSubscription, $expiry, $this->actor());

        return redirect()->route('super-admin.subscriptions.show', $restaurantSubscription)
            ->with('status', 'Subscription expiry extended and recorded in history.');
    }

    public function updateStatus(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $this->ensureBusinessSubscription($restaurantSubscription);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'cancelled'])],
        ]);

        $this->subscriptions->changeStatus($restaurantSubscription, $validated['status'], $this->actor());

        $message = match ($validated['status']) {
            'active' => 'Subscription reactivated successfully.',
            'suspended' => 'Subscription suspended successfully.',
            'cancelled' => 'Subscription cancelled. Historical records have been retained.',
        };

        return redirect()->route('super-admin.subscriptions.show', $restaurantSubscription)->with('status', $message);
    }

    public function history(Request $request): View
    {
        $query = RestaurantSubscriptionEvent::query()
            ->whereHas('restaurant', fn (Builder $restaurant) => $restaurant->where('slug', 'not like', 'demo-tb-2026-%'))
            ->with(['restaurant', 'actor', 'subscription']);
        $validated = $request->validate([
            'restaurant_id' => ['nullable', 'integer'],
        ]);
        $restaurantId = $validated['restaurant_id'] ?? '';

        if ($restaurantId !== '') {
            $query->where('restaurant_id', $restaurantId);
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($builder) use ($search): void {
                $builder->whereHas('restaurant', fn ($restaurant) => $restaurant
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%"))
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $events = $query->latest('created_at')->paginate(20)->withQueryString();

        return view('super-admin.subscriptions.history', [
            'events' => $events,
            'restaurants' => Restaurant::query()->where('slug', 'not like', 'demo-tb-2026-%')->orderBy('name')->get(['id', 'name']),
            'restaurantId' => $restaurantId,
            'search' => $request->input('search', ''),
        ]);
    }

    private function listing(Request $request, bool $expiring): View
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $databaseNow = $now->utc();
        $query = $this->realSubscriptions(RestaurantSubscription::query())
            ->with(['restaurant.owners', 'payments' => fn ($payments) => $this->realPayments($payments->getQuery())]);

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($builder) use ($search): void {
                $builder->where('plan_name', 'like', "%{$search}%")
                    ->orWhere('plan_code', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhereHas('restaurant', fn ($restaurant) => $restaurant
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('owners', fn ($owner) => $owner
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")))
                    ->orWhereHas('payments', fn ($payment) => $payment
                        ->where('transaction_reference', 'like', "%{$search}%"));
            });
        }

        $statusFilter = $request->input('status', '');
        if ($statusFilter !== '' && in_array($statusFilter, [
            'pending_payment', 'pending_approval', 'scheduled', 'active', 'trial', 'expired', 'suspended', 'cancelled',
        ], true)) {
            if ($statusFilter === 'expired') {
                $query->where(function ($builder) use ($databaseNow): void {
                    $builder->where('status', 'expired')
                        ->orWhere(function ($legacy) use ($databaseNow): void {
                            $legacy->whereIn('status', ['active', 'trial'])->where('expires_at', '<', $databaseNow);
                        });
                });
            } elseif (in_array($statusFilter, ['active', 'trial'], true)) {
                $query->where('status', $statusFilter)->where('starts_at', '<=', $databaseNow)->where('expires_at', '>=', $databaseNow);
            } else {
                $query->where('status', $statusFilter);
            }
        }

        $paymentStatusFilter = $request->input('payment_status', '');
        if (in_array($paymentStatusFilter, ['pending', 'approved', 'rejected', 'refunded'], true)) {
            $query->whereHas('payments', function (Builder $payments) use ($paymentStatusFilter): void {
                $this->realPayments($payments)->where('status', $paymentStatusFilter);
            });
        } elseif ($paymentStatusFilter === 'unpaid') {
            $query->whereDoesntHave('payments', fn (Builder $payments) => $this->realPayments($payments)->whereIn('status', ['pending', 'approved', 'refunded']));
        }

        if ($expiring) {
            $query->whereIn('status', ['active', 'trial'])
                ->where('starts_at', '<=', $databaseNow)
                ->whereBetween('expires_at', [$databaseNow, $now->addDays(30)->utc()]);
        }

        $sort = in_array($request->input('sort'), ['plan_name', 'starts_at', 'expires_at', 'created_at'], true)
            ? $request->input('sort')
            : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $subscriptions = $query->orderBy($sort, $direction)->paginate(15)->withQueryString();
        $paymentQueue = $this->realPayments(SubscriptionPayment::query())
            ->with(['restaurant.owners', 'subscription'])
            ->where('status', 'pending')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->paginate(10, ['*'], 'approvals_page')
            ->withQueryString();
        $paymentHistory = $this->realPayments(SubscriptionPayment::query())
            ->with(['restaurant.owners', 'subscription'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString();
        $refundHistory = SubscriptionPaymentRefund::query()
            ->whereHas('payment', fn (Builder $payment) => $this->realPayments($payment))
            ->with(['payment.restaurant', 'administrator'])
            ->orderByDesc('refunded_at')
            ->paginate(10, ['*'], 'refunds_page')
            ->withQueryString();
        $allSubscriptions = $this->realSubscriptions(RestaurantSubscription::query())->get([
            'id', 'restaurant_id', 'subscription_plan_id', 'price', 'currency', 'billing_period',
            'duration_months', 'duration_days', 'status', 'is_trial', 'starts_at', 'expires_at',
        ]);
        $paymentSummaries = $this->payments->subscriptionPaymentSummaries($allSubscriptions);
        $realPayments = $this->realPayments(SubscriptionPayment::query());
        $approvedPayments = (clone $realPayments)->whereIn('status', ['approved', 'refunded'])
            ->with('refunds:id,subscription_payment_id,amount')
            ->get(['id', 'amount']);
        $collectedCents = 0;
        foreach ($approvedPayments as $payment) {
            $refundedCents = $payment->refunds->sum(
                fn (SubscriptionPaymentRefund $refund): int => SubscriptionPayment::toCents((string) $refund->amount),
            );
            $collectedCents += max(0, SubscriptionPayment::toCents((string) $payment->amount) - $refundedCents);
        }
        $refundedCents = 0;
        foreach (SubscriptionPaymentRefund::query()
            ->whereHas('payment', fn (Builder $payment) => $this->realPayments($payment))
            ->get(['amount']) as $refund) {
            $refundedCents += SubscriptionPayment::toCents((string) $refund->amount);
        }
        $pendingAmountCents = 0;
        foreach ($allSubscriptions as $allSubscription) {
            if (in_array($allSubscription->status, ['pending_payment', 'pending_approval'], true)) {
                $pendingAmountCents += SubscriptionPayment::toCents(
                    $paymentSummaries[$allSubscription->id]['outstanding'],
                );
            }
        }

        return view('super-admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'search' => $request->input('search', ''),
            'statusFilter' => $statusFilter,
            'paymentStatusFilter' => $paymentStatusFilter,
            'sort' => $sort,
            'direction' => $direction,
            'expiring' => $expiring,
            'paymentSummaries' => $paymentSummaries,
            'paymentQueue' => $paymentQueue,
            'paymentHistory' => $paymentHistory,
            'refundHistory' => $refundHistory,
            'summary' => [
                'total_subscriptions' => $this->realSubscriptions(RestaurantSubscription::query())->count(),
                'active_subscriptions' => $this->realSubscriptions(RestaurantSubscription::query())->where('status', 'active')
                    ->where('starts_at', '<=', $databaseNow)->where('expires_at', '>=', $databaseNow)->count(),
                'pending_payments' => $this->realSubscriptions(RestaurantSubscription::query())->where('status', 'pending_payment')->count(),
                'pending_approvals' => (clone $realPayments)->where('status', 'pending')->count(),
                'expired_subscriptions' => $this->realSubscriptions(RestaurantSubscription::query())->where(function ($builder) use ($databaseNow): void {
                    $builder->where('status', 'expired')
                        ->orWhere(fn ($legacy) => $legacy->whereIn('status', ['active', 'trial'])->where('expires_at', '<', $databaseNow));
                })->count(),
                'collected' => SubscriptionPayment::formatCents($collectedCents),
                'pending_amount' => SubscriptionPayment::formatCents($pendingAmountCents),
                'refunded' => SubscriptionPayment::formatCents($refundedCents),
            ],
        ]);
    }

    private function actor(): SuperAdmin
    {
        return Auth::guard('super_admin')->user();
    }

    private function realSubscriptions(Builder $query): Builder
    {
        return $query->whereHas('restaurant', fn (Builder $restaurant) => $restaurant
            ->where('slug', 'not like', 'demo-tb-2026-%'));
    }

    private function realPayments(Builder $query): Builder
    {
        return $query->where(fn (Builder $nested) => $nested
            ->whereNull('notes')->orWhere('notes', 'not like', 'DEMO DATA ONLY%'))
            ->whereHas('restaurant', fn (Builder $restaurant) => $restaurant
                ->where('slug', 'not like', 'demo-tb-2026-%'));
    }

    private function ensureBusinessSubscription(RestaurantSubscription $subscription): void
    {
        abort_if(str_starts_with((string) $subscription->restaurant?->slug, 'demo-tb-2026-'), 404);
    }
}
