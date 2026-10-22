<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformAnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(
                ['nullable', 'date_format:Y-m-d'],
                $request->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
        ]);
        $timezone = config('app.timezone');
        $to = CarbonImmutable::parse($filters['date_to'] ?? now($timezone)->toDateString(), $timezone)->endOfDay();
        $from = CarbonImmutable::parse($filters['date_from'] ?? $to->subDays(29)->toDateString(), $timezone)->startOfDay();
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['date_from' => 'The start date must be on or before the end date.']);
        }

        $rangeDays = $from->diffInDays($to) + 1;
        $previousTo = $from->subSecond();
        $previousFrom = $previousTo->subDays($rangeDays - 1)->startOfDay();
        $now = CarbonImmutable::now($timezone)->utc();

        $restaurants = $this->realRestaurants(Restaurant::query());
        $newRestaurants = (clone $restaurants)->whereBetween('created_at', [$from->utc(), $to->utc()])->count();
        $previousRestaurants = (clone $restaurants)->whereBetween('created_at', [$previousFrom->utc(), $previousTo->utc()])->count();

        $subscriptions = $this->realSubscriptions(RestaurantSubscription::query());
        $newSubscriptions = (clone $subscriptions)->whereBetween('created_at', [$from->utc(), $to->utc()])->count();
        $previousSubscriptions = (clone $subscriptions)->whereBetween('created_at', [$previousFrom->utc(), $previousTo->utc()])->count();
        $activeSubscriptions = (clone $subscriptions)->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $now)->where('expires_at', '>=', $now)->count();
        $cancellationEvents = RestaurantSubscriptionEvent::query()
            ->where('action', 'cancelled')
            ->whereBetween('created_at', [$from->utc(), $to->utc()]);
        $cancellationEvents->whereHas('restaurant', fn (Builder $restaurant) => $this->realRestaurants($restaurant));
        $cancelledSubscriptions = $cancellationEvents->count();

        $payments = $this->realPayments(SubscriptionPayment::query())
            ->whereIn('status', ['approved', 'refunded'])
            ->whereBetween('paid_at', [$from->utc(), $to->utc()]);
        $grossByCurrency = (clone $payments)
            ->select('currency')
            ->selectRaw('COALESCE(SUM(CAST(ROUND(amount * 100) AS INTEGER)), 0) AS gross_cents')
            ->groupBy('currency')->orderBy('currency')->get()->keyBy('currency');
        $refundsByCurrency = DB::table('subscription_payment_refunds')
            ->join('subscription_payments', 'subscription_payments.id', '=', 'subscription_payment_refunds.subscription_payment_id')
            ->whereIn('subscription_payment_refunds.subscription_payment_id', (clone $payments)->select('subscription_payments.id'))
            ->select('subscription_payments.currency')
            ->selectRaw('COALESCE(SUM(CAST(ROUND(subscription_payment_refunds.amount * 100) AS INTEGER)), 0) AS refund_cents')
            ->groupBy('subscription_payments.currency')->get()->keyBy('currency');
        $revenue = $grossByCurrency->map(function ($row, string $currency) use ($refundsByCurrency): array {
            $grossCents = (int) $row->gross_cents;
            $refundCents = (int) ($refundsByCurrency->get($currency)->refund_cents ?? 0);

            return [
                'currency' => $currency,
                'gross' => SubscriptionPayment::formatCents($grossCents),
                'refunds' => SubscriptionPayment::formatCents($refundCents),
                'net' => $this->formatSignedCents($grossCents - $refundCents),
            ];
        })->values();

        $paymentStatuses = $this->realPayments(SubscriptionPayment::query())
            ->whereBetween('paid_at', [$from->utc(), $to->utc()])
            ->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
        $activePlanMix = (clone $subscriptions)
            ->whereIn('status', ['active', 'trial'])->where('starts_at', '<=', $now)->where('expires_at', '>=', $now)
            ->select('plan_name')->selectRaw('COUNT(*) AS total')
            ->groupBy('plan_name')->orderByDesc('total')->orderBy('plan_name')->limit(8)->get();

        $trendQuery = $this->realRestaurants(Restaurant::query());
        $driver = DB::connection()->getDriverName();
        $monthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $restaurantTrend = $trendQuery->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->selectRaw($monthExpression.' AS period, COUNT(*) AS total')
            ->groupBy('period')->orderBy('period')->get();

        return view('super-admin.analytics.index', [
            'filters' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'summary' => [
                'new_restaurants' => $newRestaurants,
                'previous_restaurants' => $previousRestaurants,
                'new_subscriptions' => $newSubscriptions,
                'previous_subscriptions' => $previousSubscriptions,
                'active_subscriptions' => $activeSubscriptions,
                'cancelled_subscriptions' => $cancelledSubscriptions,
            ],
            'revenue' => $revenue,
            'paymentStatuses' => $paymentStatuses,
            'activePlanMix' => $activePlanMix,
            'restaurantTrend' => $restaurantTrend,
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

    private function formatSignedCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.SubscriptionPayment::formatCents($absolute);
    }
}
