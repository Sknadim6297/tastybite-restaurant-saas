<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\PaymentManagementService;
use App\Services\PlatformSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $payments = $query->with(['restaurant.owners', 'plan'])
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

    public function create(): View
    {
        $preferences = $this->settings->group('payment');
        abort_unless($preferences['manual_enabled'], 403, 'Manual payment recording is disabled in platform settings.');

        return view('super-admin.payments.create', [
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'plans' => SubscriptionPlan::query()->where('status', 'active')->orderBy('name')->get(),
            'paidAt' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d\TH:i'),
            'paymentPreferences' => $preferences,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $preferences = $this->settings->group('payment');
        abort_unless($preferences['manual_enabled'], 403, 'Manual payment recording is disabled in platform settings.');

        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'currency' => ['required', Rule::in(['INR'])],
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
            ->with('status', 'Payment recorded as pending. No subscription was changed.');
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

        return back()->with('status', 'Payment approved and the linked subscription period was recorded.');
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
        $byDate = (clone $base)->selectRaw('DATE(paid_at) as label, COUNT(*) as payment_count, SUM(CASE WHEN status IN ("approved", "refunded") THEN amount ELSE 0 END) as gross')
            ->groupByRaw('DATE(paid_at)')->orderByDesc('label')->limit(90)->get();

        $gross = SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $gross));

        return view('super-admin.payments.reports', [
            'filters' => $filters,
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'byRestaurant' => $byRestaurant,
            'byPlan' => $byPlan,
            'byStatus' => $byStatus,
            'byDate' => $byDate,
            'gross' => $gross,
            'refundTotal' => $refundTotal,
            'net' => SubscriptionPayment::formatCents(
                SubscriptionPayment::toCents((string) $gross) - SubscriptionPayment::toCents((string) $refundTotal)
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
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
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
}
