<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantAuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SuperAdmin;
use App\Services\PaymentManagementService;
use App\Services\PlatformSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentManagementController extends Controller
{
    public function __construct(
        private readonly PaymentManagementService $payments,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request, 'all');
        $query = $this->filteredPayments($filters);

        $payments = $query->with(['restaurant.owners', 'plan', 'subscription'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $realPayments = $this->realPayments();
        $approved = $this->sumCents((clone $realPayments)->whereIn('status', ['approved', 'refunded']));
        $refundTotal = $this->sumCents(SubscriptionPaymentRefund::query()
            ->whereHas('payment', fn (Builder $query) => $this->excludeDemoNotes($query)));

        return view('super-admin.payments.index', [
            'payments' => $payments,
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'summary' => [
                'total_collected' => $this->subtractCents($approved, $refundTotal),
                'month' => $this->sumCents((clone $realPayments)->whereIn('status', ['approved', 'refunded'])
                    ->whereBetween('paid_at', [
                        CarbonImmutable::now(config('app.timezone'))->startOfMonth()->utc(),
                        CarbonImmutable::now(config('app.timezone'))->endOfMonth()->utc(),
                    ])),
                'pending' => $this->sumCents((clone $realPayments)->where('status', 'pending')),
                'successful' => (clone $realPayments)->whereIn('status', ['approved', 'refunded'])->count(),
                'rejected' => (clone $realPayments)->where('status', 'rejected')->count(),
                'refunded' => $refundTotal,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $preferences = $this->settings->group('payment');
        abort_unless($preferences['manual_enabled'], 403, 'Manual payment recording is disabled in platform settings.');

        $restaurantId = $request->integer('restaurant_id');
        $subscriptionId = $request->integer('restaurant_subscription_id');
        $subscriptionBelongsToRestaurant = $restaurantId > 0 && $subscriptionId > 0
            && RestaurantSubscription::query()
                ->where('restaurant_id', $restaurantId)
                ->whereKey($subscriptionId)
                ->exists();

        return view('super-admin.payments.create', [
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'paidAt' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d\TH:i'),
            'paymentPreferences' => $preferences,
            'selectedRestaurantId' => $subscriptionBelongsToRestaurant ? $restaurantId : null,
            'selectedSubscriptionId' => $subscriptionBelongsToRestaurant ? $subscriptionId : null,
        ]);
    }

    public function restaurantSubscriptions(Restaurant $restaurant): JsonResponse
    {
        $owner = $restaurant->owners()->orderBy('id')->first(['id', 'name', 'email', 'mobile']);
        $subscriptions = $restaurant->subscriptions()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get([
                'id', 'restaurant_id', 'subscription_plan_id', 'plan_name', 'plan_code',
                'price', 'currency', 'billing_period', 'duration_months', 'duration_days',
                'status', 'is_trial', 'starts_at', 'expires_at',
            ]);
        $summaries = $this->payments->subscriptionPaymentSummaries($subscriptions);
        $timezone = config('app.timezone');

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'email' => $restaurant->email ?: 'Not provided',
                'phone' => $restaurant->phone ?: 'Not provided',
            ],
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'mobile' => $owner->mobile ?: null,
                'edit_url' => route('super-admin.restaurant-owners.edit', $owner),
            ] : null,
            'subscriptions' => $subscriptions->map(function ($subscription) use ($summaries, $timezone): array {
                return [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan_name,
                    'plan_code' => $subscription->plan_code,
                    'billing_duration' => $this->billingDuration($subscription),
                    'starts_at' => in_array($subscription->status, ['pending_payment', 'pending_approval'], true)
                        ? 'Not Activated Yet'
                        : ($subscription->starts_at?->timezone($timezone)->format('M j, Y') ?? 'Not available'),
                    'expires_at' => in_array($subscription->status, ['pending_payment', 'pending_approval'], true)
                        ? 'Not Activated Yet'
                        : ($subscription->expires_at?->timezone($timezone)->format('M j, Y') ?? 'Not available'),
                    'status' => $subscription->effectiveStatus(),
                    'status_label' => match ($subscription->effectiveStatus()) {
                        'pending_payment' => 'Pending Payment',
                        'pending_approval' => 'Pending Approval',
                        'scheduled' => 'Scheduled',
                        'active' => 'Active',
                        'trial' => 'Trial',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                        'suspended' => 'Suspended',
                        default => 'Not available',
                    },
                    'price' => number_format((float) $subscription->price, 2, '.', ''),
                    'currency' => $subscription->currency,
                    ...$summaries[$subscription->id],
                ];
            }),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $preferences = $this->settings->group('payment');
        abort_unless($preferences['manual_enabled'], 403, 'Manual payment recording is disabled in platform settings.');

        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'restaurant_subscription_id' => ['required', 'integer', 'exists:restaurant_subscriptions,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in($preferences['allowed_methods'])],
            'transaction_reference' => [
                'nullable', 'string', 'max:255',
                Rule::unique('subscription_payments', 'transaction_reference')->where(
                    fn ($query) => $query->where('payment_method', $request->input('payment_method'))
                ),
            ],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'proof' => [$preferences['proof_required'] ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $payment = $this->payments->create($validated, $this->actor(), $request->file('proof'));

        return redirect()->route('super-admin.payments.show', $payment)
            ->with('status', 'Payment recorded as pending. Subscription activation still requires payment approval.');
    }

    public function show(SubscriptionPayment $payment): View
    {
        $payment->load([
            'restaurant.owners',
            'plan',
            'creator',
            'approver',
            'rejector',
            'subscription',
            'refunds.administrator',
            'invoice',
        ]);
        $audit = RestaurantAuditLog::query()
            ->with('actor')
            ->where('target_type', 'subscription_payment')
            ->where('target_id', $payment->id)
            ->latest()
            ->get();

        return view('super-admin.payments.show', compact('payment', 'audit'));
    }

    public function approve(SubscriptionPayment $payment): RedirectResponse
    {
        $this->payments->approve($payment, $this->actor());

        return redirect()->route('super-admin.payments.show', $payment)
            ->with('status', 'Payment approved successfully.');
    }

    public function reject(Request $request, SubscriptionPayment $payment): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->payments->reject($payment, $this->actor(), $validated['reason']);

        return back()->with('status', 'Payment rejected.');
    }

    public function refund(Request $request, SubscriptionPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'upi', 'other'])],
            'reference' => [
                'nullable', 'string', 'max:255',
                Rule::unique('subscription_payment_refunds', 'reference')->where(
                    fn ($query) => $query->where('method', $request->input('method'))
                ),
            ],
            'refunded_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->payments->refund($payment, $this->actor(), $validated);

        return back()->with('status', 'Manual refund recorded. This record does not confirm funds were returned.');
    }

    public function proof(SubscriptionPayment $payment): StreamedResponse|BinaryFileResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->download($payment->proof_path, 'payment-proof-'.$payment->id);
    }

    public function reports(Request $request): View
    {
        $filters = $this->filters($request, 'real');
        $base = $this->filteredPayments($filters);
        $filteredPaymentIds = (clone $base)->select('subscription_payments.id');
        $refundTotal = SubscriptionPaymentRefund::query()
            ->whereIn('subscription_payment_id', $filteredPaymentIds)
            ->sum('amount');
        $gross = SubscriptionPayment::query()->whereIn('id', $filteredPaymentIds)
            ->whereIn('status', ['approved', 'refunded'])
            ->sum('amount');

        $byRestaurant = (clone $base)->join('restaurants', 'restaurants.id', '=', 'subscription_payments.restaurant_id')
            ->selectRaw('restaurants.name as label, COUNT(*) as payment_count, SUM(CASE WHEN subscription_payments.status IN ("approved", "refunded") THEN subscription_payments.amount ELSE 0 END) as gross')
            ->groupBy('restaurants.id', 'restaurants.name')->orderBy('restaurants.name')->get();
        $byPlan = (clone $base)->selectRaw('plan_name as label, COUNT(*) as payment_count, SUM(CASE WHEN status IN ("approved", "refunded") THEN amount ELSE 0 END) as gross')
            ->groupBy('plan_name')->orderBy('plan_name')->get();
        $byStatus = (clone $base)->selectRaw('status as label, COUNT(*) as payment_count, SUM(amount) as gross')
            ->groupBy('status')->orderBy('status')->get();
        $byMethod = (clone $base)->selectRaw('payment_method as label, COUNT(*) as payment_count, SUM(CASE WHEN status IN ("approved", "refunded") THEN amount ELSE 0 END) as gross')
            ->groupBy('payment_method')->orderBy('payment_method')->get();
        $byDate = (clone $base)->selectRaw('DATE(paid_at) as label, COUNT(*) as payment_count, SUM(CASE WHEN status IN ("approved", "refunded") THEN amount ELSE 0 END) as gross')
            ->groupByRaw('DATE(paid_at)')->orderByDesc('label')->limit(90)->get();
        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', paid_at)"
            : "DATE_FORMAT(paid_at, '%Y-%m')";
        $byMonth = (clone $base)->selectRaw("{$monthExpression} as label, COUNT(*) as payment_count, SUM(CASE WHEN status IN ('approved', 'refunded') THEN amount ELSE 0 END) as gross")
            ->groupByRaw($monthExpression)->orderByDesc('label')->limit(24)->get();

        $gross = SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $gross));
        $refundTotal = SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $refundTotal));

        return view('super-admin.payments.reports', [
            'filters' => $filters,
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'byRestaurant' => $byRestaurant,
            'byPlan' => $byPlan,
            'byStatus' => $byStatus,
            'byMethod' => $byMethod,
            'byDate' => $byDate,
            'byMonth' => $byMonth,
            'gross' => $gross,
            'refundTotal' => $refundTotal,
            'net' => SubscriptionPayment::formatCents(
                SubscriptionPayment::toCents($gross) - SubscriptionPayment::toCents($refundTotal)
            ),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, 'all');
        $payments = $this->filteredPayments($filters)
            ->with(['restaurant.owners', 'plan', 'refunds'])
            ->orderByDesc('paid_at')->orderByDesc('id');

        return response()->streamDownload(static function () use ($payments): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Payment ID', 'Restaurant', 'Restaurant Owner', 'Subscription Plan', 'Amount', 'Currency', 'Method', 'Transaction Reference', 'Status', 'Payment Date', 'Refunded Amount', 'Demo Record']);

            foreach ($payments->lazy(500) as $payment) {
                $row = [
                    $payment->id,
                    $payment->restaurant->name,
                    $payment->restaurant->owners->first()?->name ?? '',
                    $payment->plan_name,
                    $payment->amount,
                    $payment->currency,
                    $payment->payment_method,
                    $payment->transaction_reference,
                    $payment->status,
                    $payment->paid_at?->toIso8601String(),
                    $payment->refunds->sum('amount'),
                    $payment->isDemo() ? 'Yes — not actual money' : 'No',
                ];
                fputcsv($output, array_map(static function ($value) {
                    $value = (string) ($value ?? '');

                    return preg_match('/^[\s]*[=+\-@]/', $value) ? "'".$value : $value;
                }, $row));
            }

            fclose($output);
        }, 'tastybite-payments.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request, string $defaultScope): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'refunded'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(
                ['nullable', 'date_format:Y-m-d'],
                $request->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
            'scope' => ['nullable', Rule::in(['all', 'real', 'demo'])],
        ]);

        $filters['scope'] ??= $defaultScope;

        return $filters;
    }

    private function filteredPayments(array $filters): Builder
    {
        return SubscriptionPayment::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('transaction_reference', 'like', "%{$search}%")
                        ->orWhere('plan_name', 'like', "%{$search}%")
                        ->orWhereHas('restaurant', fn (Builder $restaurant) => $restaurant
                            ->where('name', 'like', "%{$search}%")
                            ->orWhereHas('owners', fn (Builder $owner) => $owner
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")));
                });
            })
            ->when($filters['restaurant_id'] ?? null, fn (Builder $query, int $id) => $query->where('subscription_payments.restaurant_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('subscription_payments.status', $status))
            ->when(($filters['scope'] ?? 'all') === 'demo', fn (Builder $query) => $query->where('subscription_payments.notes', 'like', 'DEMO DATA ONLY%'))
            ->when(($filters['scope'] ?? 'all') === 'real', fn (Builder $query) => $this->excludeDemoNotes($query))
            ->when($filters['date_from'] ?? null, function (Builder $query, string $date): void {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'))->utc();
                $query->where('paid_at', '>=', $from);
            })
            ->when($filters['date_to'] ?? null, function (Builder $query, string $date): void {
                $until = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'))->addDay()->utc();
                $query->where('paid_at', '<', $until);
            });
    }

    private function realPayments(): Builder
    {
        return $this->excludeDemoNotes(SubscriptionPayment::query());
    }

    private function excludeDemoNotes(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->whereNull('notes')->orWhere('notes', 'not like', 'DEMO DATA ONLY%');
        });
    }

    private function actor(): SuperAdmin
    {
        return Auth::guard('super_admin')->user();
    }

    private function sumCents(Builder $query): string
    {
        $cents = $query->selectRaw('COALESCE(SUM(CAST(ROUND(amount * 100) AS INTEGER)), 0) AS aggregate_cents')
            ->value('aggregate_cents');

        return SubscriptionPayment::formatCents((int) $cents);
    }

    private function subtractCents(string $left, string $right): string
    {
        $toCents = static function (string $amount): int {
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

            return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        };
        $cents = $toCents($left) - $toCents($right);
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function billingDuration(RestaurantSubscription $subscription): string
    {
        if ($subscription->is_trial) {
            return 'Trial';
        }

        return match ($subscription->billing_period) {
            'monthly' => 'Monthly · 1 calendar month',
            'quarterly' => 'Quarterly · 3 calendar months',
            'yearly' => 'Yearly · 12 calendar months',
            default => $subscription->duration_months
                ? 'Custom · '.$subscription->duration_months.' calendar months'
                : 'Custom · '.$subscription->duration_days.' days',
        };
    }
}
