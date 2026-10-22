<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformReportController extends Controller
{
    public function index(Request $request): View
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $restaurants = $this->realRestaurants(Restaurant::query());
        $subscriptions = $this->realSubscriptions(RestaurantSubscription::query());
        $payments = $this->realPayments(SubscriptionPayment::query());
        $counts = [
            'restaurants' => (clone $restaurants)->count(),
            'active_restaurants' => (clone $restaurants)->where('status', 'active')->count(),
            'owners' => User::query()->where('role', 'owner')->where('status', 'active')
                ->whereIn('restaurant_id', (clone $restaurants)->select('id'))->count(),
            'active_subscriptions' => (clone $subscriptions)->whereIn('status', ['active', 'trial'])
                ->where('starts_at', '<=', $now->utc())->where('expires_at', '>=', $now->utc())->count(),
            'expiring_subscriptions' => (clone $subscriptions)->whereIn('status', ['active', 'trial'])
                ->where('starts_at', '<=', $now->utc())->whereBetween('expires_at', [$now->utc(), $now->addDays(30)->utc()])->count(),
            'expired_subscriptions' => (clone $subscriptions)->whereIn('status', ['active', 'trial'])
                ->where('expires_at', '<', $now->utc())->count(),
            'pending_payments' => (clone $payments)->where('status', 'pending')->count(),
            'gross' => $this->gross($payments),
            'refunds' => $this->refundsForPayments($payments),
        ];
        $counts['net'] = $this->subtract($counts['gross'], $counts['refunds']);

        $months = [];
        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $now->startOfMonth()->subMonths($offset);
            $start = $month->utc();
            $end = $month->endOfMonth()->utc();
            $gross = $this->gross($this->realPayments(SubscriptionPayment::query())
                ->whereBetween('paid_at', [$start, $end]));
            $refundQuery = SubscriptionPaymentRefund::query()
                ->whereBetween('refunded_at', [$start, $end])
                ->whereHas('payment', fn (Builder $query) => $this->realPayments($query));
            $refunds = $this->sumCents($refundQuery, 'amount');
            $months[] = [
                'label' => $month->format('M y'),
                'gross' => $gross,
                'refunds' => $refunds,
                'net' => $this->subtract($gross, (string) $refunds),
            ];
        }

        return view('super-admin.reports.index', compact('counts', 'months'));
    }

    public function restaurants(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->realRestaurants(Restaurant::query()->with(['owners']));
        $query->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '<=', $date))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
            ->when($filters['plan_id'] ?? null, fn (Builder $builder, int $plan) => $builder->whereHas('subscriptions', fn (Builder $subscriptions) => $subscriptions->where('subscription_plan_id', $plan)))
            ->when($filters['subscription_status'] ?? null, fn (Builder $builder, string $status) => $builder->whereHas(
                'subscriptions',
                fn (Builder $subscriptions) => $this->subscriptionStatusFilter($subscriptions, $status),
            ));

        $restaurants = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString();
        $this->attachCurrentSubscriptions($restaurants->getCollection());

        return view('super-admin.reports.restaurants', [
            'restaurants' => $restaurants,
            'plans' => SubscriptionPlan::query()->where('code', 'not like', 'DEMO-TB-2026-%')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function subscriptions(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->realSubscriptions(RestaurantSubscription::query()->with(['restaurant', 'events']))
            ->when($filters['plan_id'] ?? null, fn (Builder $builder, int $plan) => $builder->where('subscription_plan_id', $plan))
            ->when($filters['subscription_status'] ?? null, fn (Builder $builder, string $status) => $this->subscriptionStatusFilter($builder, $status))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('starts_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('starts_at', '<=', $date))
            ->when($filters['expiring_days'] ?? null, function (Builder $builder, int $days): void {
                $now = CarbonImmutable::now(config('app.timezone'));
                $builder->whereIn('status', ['active', 'trial'])
                    ->whereBetween('expires_at', [$now->utc(), $now->addDays($days)->utc()]);
            });

        $subscriptions = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString();

        return view('super-admin.reports.subscriptions', [
            'subscriptions' => $subscriptions,
            'plans' => SubscriptionPlan::query()->where('code', 'not like', 'DEMO-TB-2026-%')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function payments(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->realPayments(SubscriptionPayment::query()->with(['restaurant', 'plan', 'refunds']))
            ->when($filters['restaurant_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('restaurant_id', $id))
            ->when($filters['plan_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('subscription_plan_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '<=', $date));

        return view('super-admin.reports.payments', [
            'payments' => $query->orderByDesc('paid_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'restaurants' => $this->realRestaurants(Restaurant::query())->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function revenue(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->realPayments(SubscriptionPayment::query())
            ->whereIn('subscription_payments.status', ['approved', 'refunded'])
            ->when($filters['restaurant_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('restaurant_id', $id))
            ->when($filters['plan_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('subscription_plan_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '<=', $date));
        $paymentIds = (clone $query)->select('id');
        $gross = $this->gross(clone $query);
        $refunds = $this->sumCents(SubscriptionPaymentRefund::query()->whereIn('subscription_payment_id', $paymentIds), 'amount');
        $byPlan = (clone $query)->selectRaw('plan_name as label, COUNT(*) as payment_count, SUM(CAST(ROUND(amount * 100) AS INTEGER)) as gross_cents')
            ->groupBy('plan_name')->orderBy('plan_name')->get()
            ->map(fn ($row) => $row->setAttribute('gross', SubscriptionPayment::formatCents((int) $row->gross_cents)));
        $byRestaurant = (clone $query)->join('restaurants', 'restaurants.id', '=', 'subscription_payments.restaurant_id')
            ->selectRaw('restaurants.name as label, COUNT(*) as payment_count, SUM(CAST(ROUND(subscription_payments.amount * 100) AS INTEGER)) as gross_cents')
            ->groupBy('restaurants.id', 'restaurants.name')->orderBy('restaurants.name')->get()
            ->map(fn ($row) => $row->setAttribute('gross', SubscriptionPayment::formatCents((int) $row->gross_cents)));
        $byDay = (clone $query)->selectRaw('DATE(paid_at) as label, COUNT(*) as payment_count, SUM(CAST(ROUND(amount * 100) AS INTEGER)) as gross_cents')
            ->groupByRaw('DATE(paid_at)')->orderByDesc('label')->limit(90)->get()
            ->map(fn ($row) => $row->setAttribute('gross', SubscriptionPayment::formatCents((int) $row->gross_cents)));

        return view('super-admin.reports.revenue', [
            'filters' => $filters,
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => $this->subtract($gross, $refunds),
            'byPlan' => $byPlan,
            'byRestaurant' => $byRestaurant,
            'byDay' => $byDay,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['restaurants', 'subscriptions', 'payments', 'revenue'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(
                ['nullable', 'date_format:Y-m-d'],
                $request->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive', 'trial', 'expired', 'cancelled', 'pending', 'approved', 'rejected', 'refunded'])],
            'subscription_status' => ['nullable', Rule::in(['active', 'trial', 'scheduled', 'pending_payment', 'pending_approval', 'expired', 'suspended', 'cancelled'])],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $type = $validated['type'];

        if ($type === 'restaurants') {
            $query = $this->realRestaurants(Restaurant::query()->with('owners'))
                ->when($validated['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
                ->when($validated['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '>=', $date))
                ->when($validated['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('created_at', '<=', $date));
            $query->when($validated['plan_id'] ?? null, fn (Builder $builder, int $plan) => $builder->whereHas('subscriptions', fn (Builder $subscriptions) => $subscriptions->where('subscription_plan_id', $plan)))
                ->when($validated['subscription_status'] ?? null, function (Builder $builder, string $status): void {
                    $builder->whereHas('subscriptions', fn (Builder $subscriptions) => $this->subscriptionStatusFilter($subscriptions, $status));
                });
            $headers = ['Restaurant ID', 'Restaurant Name', 'Owner', 'Status', 'Registered At'];
            $rows = $query->orderByDesc('id')->lazy(500)->map(fn (Restaurant $restaurant) => [
                $restaurant->id, $restaurant->name, $restaurant->owners->first()?->name, $restaurant->status, $restaurant->created_at?->toIso8601String(),
            ]);
        } elseif ($type === 'subscriptions') {
            $query = $this->realSubscriptions(RestaurantSubscription::query()->with('restaurant'))
                ->when($validated['plan_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('subscription_plan_id', $id))
                ->when($validated['subscription_status'] ?? null, fn (Builder $builder, string $status) => $this->subscriptionStatusFilter($builder, $status))
                ->when($validated['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('starts_at', '>=', $date))
                ->when($validated['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('starts_at', '<=', $date));
            $headers = ['Subscription ID', 'Restaurant', 'Plan', 'Starts At', 'Expires At', 'Status', 'Renewal Of'];
            $rows = $query->orderByDesc('id')->lazy(500)->map(fn (RestaurantSubscription $subscription) => [
                $subscription->id, $subscription->restaurant->name, $subscription->plan_name, $subscription->starts_at?->toIso8601String(),
                $subscription->expires_at?->toIso8601String(), $subscription->effectiveStatus(), $subscription->renewal_of_id,
            ]);
        } else {
            $query = $this->realPayments(SubscriptionPayment::query()->with(['restaurant', 'refunds']))
                ->when($validated['restaurant_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('restaurant_id', $id))
                ->when($validated['plan_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('subscription_plan_id', $id))
                ->when($validated['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
                ->when($validated['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '>=', $date))
                ->when($validated['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '<=', $date));
            if ($type === 'revenue') {
                $query->whereIn('status', ['approved', 'refunded']);
                $headers = ['Payment ID', 'Restaurant', 'Plan', 'Transaction Reference', 'Gross Amount', 'Currency', 'Payment Date', 'Refunds Recorded', 'Net Recorded'];
                $rows = $query->orderByDesc('id')->lazy(500)->map(function (SubscriptionPayment $payment): array {
                    $refunds = $this->sumCents($payment->refunds()->getQuery(), 'amount');

                    return [
                        $payment->id, $payment->restaurant->name, $payment->plan_name, $payment->transaction_reference, $payment->amount, $payment->currency,
                        $payment->paid_at?->toIso8601String(), $refunds,
                        $this->subtract((string) $payment->amount, $refunds),
                    ];
                });
            } else {
                $headers = ['Payment ID', 'Restaurant', 'Plan', 'Amount', 'Currency', 'Status', 'Method', 'Paid At', 'Recorded Refunds'];
                $rows = $query->orderByDesc('id')->lazy(500)->map(fn (SubscriptionPayment $payment) => [
                    $payment->id, $payment->restaurant->name, $payment->plan_name, $payment->amount, $payment->currency, $payment->status,
                    $payment->payment_method, $payment->paid_at?->toIso8601String(),
                    $this->sumCents($payment->refunds()->getQuery(), 'amount'),
                ]);
            }
        }

        return response()->streamDownload(static function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, array_map(static function ($value): string {
                    $value = (string) ($value ?? '');

                    return preg_match('/^[\s]*[=+\-@]/', $value) ? "'".$value : $value;
                }, $row));
            }
            fclose($output);
        }, 'tastybite-'.$type.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(
                ['nullable', 'date_format:Y-m-d'],
                $request->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive', 'pending', 'approved', 'rejected', 'refunded'])],
            'subscription_status' => ['nullable', Rule::in(['active', 'trial', 'scheduled', 'pending_payment', 'pending_approval', 'expired', 'suspended', 'cancelled'])],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
    }

    private function realRestaurants(Builder $query): Builder
    {
        return $query->where('slug', 'not like', 'demo-tb-2026-%');
    }

    private function realSubscriptions(Builder $query): Builder
    {
        return $query->whereHas('restaurant', fn (Builder $restaurant) => $this->realRestaurants($restaurant));
    }

    private function realPayments(Builder $query): Builder
    {
        return $query->where(fn (Builder $nested) => $nested
            ->whereNull('notes')->orWhere('notes', 'not like', 'DEMO DATA ONLY%'))
            ->whereHas('restaurant', fn (Builder $restaurant) => $this->realRestaurants($restaurant));
    }

    private function gross(Builder $payments): string
    {
        return $this->sumCents((clone $payments)->whereIn('status', ['approved', 'refunded']), 'amount');
    }

    private function refundsForPayments(Builder $payments): string
    {
        $ids = (clone $payments)->select('subscription_payments.id');

        return $this->sumCents(SubscriptionPaymentRefund::query()->whereIn('subscription_payment_id', $ids), 'amount');
    }

    private function sumCents(Builder $query, string $column): string
    {
        $cents = $query->selectRaw('COALESCE(SUM(CAST(ROUND('.$column.' * 100) AS INTEGER)), 0) AS aggregate_cents')
            ->value('aggregate_cents');

        return SubscriptionPayment::formatCents((int) $cents);
    }

    private function subtract(string $left, string $right): string
    {
        $cents = $this->aggregateToCents($left) - $this->aggregateToCents($right);
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function aggregateToCents(string $amount): int
    {
        if (! preg_match('/^\d{1,15}(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid aggregated decimal amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function attachCurrentSubscriptions($restaurants): void
    {
        $now = CarbonImmutable::now(config('app.timezone'))->utc();
        $ids = $restaurants->modelKeys();
        if ($ids === []) {
            return;
        }

        $current = RestaurantSubscription::query()->whereIn('restaurant_id', $ids)
            ->whereIn('status', ['active', 'trial'])->where('starts_at', '<=', $now)->where('expires_at', '>=', $now)
            ->orderByDesc('starts_at')->orderByDesc('id')->get()->unique('restaurant_id')->keyBy('restaurant_id');
        $missing = array_values(array_diff($ids, $current->keys()->all()));
        $latest = $missing === [] ? collect() : RestaurantSubscription::query()->whereIn('restaurant_id', $missing)
            ->orderByDesc('id')->get()->unique('restaurant_id')->keyBy('restaurant_id');

        foreach ($restaurants as $restaurant) {
            $restaurant->setRelation('reportSubscription', $current->get($restaurant->id) ?? $latest->get($restaurant->id));
        }
    }

    private function subscriptionStatusFilter(Builder $query, string $status): Builder
    {
        $now = CarbonImmutable::now(config('app.timezone'))->utc();

        return match ($status) {
            'expired' => $query->whereIn('status', ['active', 'trial'])->where('expires_at', '<', $now),
            'scheduled' => $query->whereIn('status', ['active', 'trial'])->where('starts_at', '>', $now),
            'active' => $query->where('status', 'active')->where('starts_at', '<=', $now)->where('expires_at', '>=', $now),
            'trial' => $query->where('status', 'trial')->where('starts_at', '<=', $now)->where('expires_at', '>=', $now),
            default => $query->where('status', $status),
        };
    }
}
