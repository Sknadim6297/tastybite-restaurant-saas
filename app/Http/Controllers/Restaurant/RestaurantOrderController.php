<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderStatusHistory;
use App\Models\RestaurantPosRefund;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableOccupancy;
use App\Models\RestaurantTableStatusHistory;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\RestaurantAccessContext;
use Carbon\CarbonImmutable;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RestaurantOrderController extends Controller
{
    public function overview(Request $request): View
    {
        $owner = $this->owner();
        [$start, $end] = $this->dateRange($request);
        $query = $this->dateScopedOrders($owner->restaurant_id, $start, $end);
        $paymentSummary = $this->paymentSummary($owner->restaurant_id, $start, $end);
        $metrics = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('fulfillment_status', 'pending')->count(),
            'preparing' => (clone $query)->where('fulfillment_status', 'preparing')->count(),
            'ready' => (clone $query)->where('fulfillment_status', 'ready')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            'gross' => (clone $query)->where('status', 'completed')->sum('subtotal'),
            'discounts' => (clone $query)->where('status', 'completed')->sum('discount'),
            'tax' => (clone $query)->where('status', 'completed')->sum('tax'),
            'net' => (clone $query)->where('status', 'completed')->sum('total'),
            'unpaid' => (clone $query)->where('payment_status', 'unpaid')->count(),
            'paidRevenue' => $paymentSummary['net'],
            'average' => (clone $query)->where('status', 'completed')->avg('total') ?? 0,
        ];

        return view('restaurant.orders.overview', [
            'owner' => $owner,
            'restaurant' => $owner->restaurant,
            'metrics' => $metrics,
            'recentOrders' => (clone $query)->withCount('items')->latest('created_at')->limit(10)->get(),
            'statusBreakdown' => (clone $query)->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')->pluck('total', 'status'),
            'typeBreakdown' => (clone $query)->select('order_type', DB::raw('COUNT(*) as total'))
                ->groupBy('order_type')->pluck('total', 'order_type'),
            'paymentBreakdown' => $this->paymentBreakdown($owner->restaurant_id, $start, $end),
            'salesTrend' => (clone $query)->where('status', 'completed')
                ->selectRaw('DATE(created_at) as day, SUM(total) as total')
                ->groupByRaw('DATE(created_at)')->orderBy('day')->get(),
            'startDate' => $start,
            'endDate' => $end,
        ]);
    }

    public function all(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'all');
    }

    public function dineIn(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'dine_in');
    }

    public function fulfillment(Request $request): View|JsonResponse
    {
        $type = $request->query('type', 'takeaway');
        abort_unless(in_array($type, ['takeaway', 'delivery'], true), 404);

        return $this->listing($request, $type);
    }

    public function billing(Request $request): View|JsonResponse
    {
        return $this->listing($request, 'billing');
    }

    public function reports(Request $request): View
    {
        $owner = $this->owner();
        [$start, $end] = $this->dateRange($request);
        $orders = $this->dateScopedOrders($owner->restaurant_id, $start, $end);
        $completedOrders = (clone $orders)->where('status', 'completed');
        $paymentSummary = $this->paymentSummary($owner->restaurant_id, $start, $end);
        $statusBreakdown = (clone $orders)
            ->select('status as order_status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return view('restaurant.orders.reports', [
            'owner' => $owner,
            'restaurant' => $owner->restaurant,
            'startDate' => $start,
            'endDate' => $end,
            'orderCount' => (clone $orders)->count(),
            'cancelledCount' => (clone $orders)->where('status', 'cancelled')->count(),
            'completedCount' => (clone $completedOrders)->count(),
            'unpaidCompletedCount' => (clone $completedOrders)->where('payment_status', 'unpaid')->count(),
            'salesTotal' => (clone $completedOrders)->sum('total'),
            'discountTotal' => (clone $completedOrders)->sum('discount'),
            'taxTotal' => (clone $completedOrders)->sum('tax'),
            'averageOrderValue' => (clone $completedOrders)->avg('total') ?? 0,
            'paidRevenue' => $paymentSummary['net'],
            'typeBreakdown' => (clone $completedOrders)
                ->select('order_type', DB::raw('COUNT(*) as total'), DB::raw('SUM(total) as sales'))
                ->groupBy('order_type')
                ->orderBy('order_type')
                ->get(),
            'statusBreakdown' => $statusBreakdown,
            'paymentBreakdown' => $this->paymentBreakdown($owner->restaurant_id, $start, $end),
            'topProducts' => DB::table('restaurant_pos_order_items as items')
                ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'items.pos_order_id')
                ->where('items.restaurant_id', $owner->restaurant_id)
                ->where('orders.restaurant_id', $owner->restaurant_id)
                ->where('orders.status', 'completed')
                ->whereBetween('orders.created_at', $this->localDateBounds($start, $end))
                ->select('items.item_name', DB::raw('SUM(items.quantity) as quantity'), DB::raw('SUM(items.quantity * items.unit_price) as sales'))
                ->groupBy('items.item_name')
                ->orderByDesc('quantity')
                ->orderBy('items.item_name')
                ->limit(10)
                ->get(),
        ]);
    }

    public function show(int $order): View
    {
        $owner = $this->owner();
        $sale = $this->tenantOrder($owner, $order);
        $nextStatuses = [
            'pending' => ['confirmed'],
            'confirmed' => ['preparing'],
            'preparing' => ['ready'],
            'ready' => match ($sale->order_type) {
                'dine_in' => ['served'],
                'delivery' => ['dispatched'],
                default => ['completed'],
            },
            'served' => ['completed'],
            'dispatched' => ['completed'],
        ][$sale->fulfillment_status] ?? [];

        return view('restaurant.orders.show', [
            'owner' => $owner,
            'restaurant' => $owner->restaurant,
            'order' => $sale->load([
                'items.ingredients',
                'creator',
                'updater',
                'payments.recordedBy',
                'refunds.recorder',
                'statusHistory',
                'diningFloor',
                'diningSection',
                'diningTable',
                'originalDiningTable',
            ]),
            'nextStatuses' => $nextStatuses,
            'adminMode' => app(RestaurantAccessContext::class)->isSuperAdminAccess(),
        ]);
    }

    public function receipt(int $order): View
    {
        $owner = $this->owner();

        return view('restaurant.orders.receipt', [
            'restaurant' => $owner->restaurant,
            'order' => $this->tenantOrder($owner, $order)->load(['items', 'payments']),
        ]);
    }

    public function updateStatus(Request $request, int $order): RedirectResponse
    {
        abort_unless(! app(RestaurantAccessContext::class)->isSuperAdminAccess(), 403);
        $owner = $this->owner();
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'preparing', 'ready', 'served', 'dispatched', 'completed'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($owner, $order, $data): void {
            $sale = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()->findOrFail($order);
            abort_unless($sale->status === 'completed' && $sale->fulfillment_status !== 'cancelled', 409);
            $previousStatus = $sale->fulfillment_status;
            $transitions = [
                'pending' => ['confirmed'],
                'confirmed' => ['preparing'],
                'preparing' => ['ready'],
                'ready' => match ($sale->order_type) {
                    'dine_in' => ['served'],
                    'delivery' => ['dispatched'],
                    default => ['completed'],
                },
                'served' => ['completed'],
                'dispatched' => ['completed'],
            ];
            abort_unless(in_array($data['status'], $transitions[$sale->fulfillment_status] ?? [], true), 422, 'That order status transition is not allowed.');
            $this->writeStatusHistory($sale, $owner, $previousStatus, $data['status'], $data['reason'] ?? null);
            $sale->update(['fulfillment_status' => $data['status'], 'updated_by' => $owner->id]);
            if ($data['status'] === 'completed' && $sale->table_id) {
                $this->releaseDiningTable($owner->id, $sale);
            }
            $this->audit($owner, 'order_status_changed', $sale, [
                'from' => $previousStatus,
                'to' => $data['status'],
                'reason' => $data['reason'] ?? null,
            ]);
        });

        return back()->with('status', 'Order status updated.');
    }

    public function recordRefund(Request $request, int $order): RedirectResponse
    {
        abort_unless(! app(RestaurantAccessContext::class)->isSuperAdminAccess(), 403);
        $owner = $this->owner();
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999999.99'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        DB::transaction(function () use ($owner, $order, $data): void {
            $sale = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()->with('payments.refunds')->findOrFail($order);
            abort_unless($sale->status === 'completed' && $sale->payment_status !== 'unpaid', 409, 'Only paid, completed orders can be refunded.');
            $payment = $sale->payments->where('status', 'recorded')->first();
            abort_unless($payment, 409, 'No recorded manual payment is available to refund.');

            $alreadyRefunded = BigDecimal::of((string) $sale->refunds->where('status', 'recorded')->sum('amount'));
            $amount = BigDecimal::of((string) $data['amount'])->toScale(2);
            $remaining = BigDecimal::of((string) $payment->amount)->minus($alreadyRefunded);
            abort_unless($amount->isLessThanOrEqualTo($remaining), 422, 'The refund exceeds the remaining recorded payment.');

            $refund = RestaurantPosRefund::create([
                'restaurant_id' => $owner->restaurant_id,
                'pos_order_id' => $sale->id,
                'payment_id' => $payment->id,
                'recorded_by' => $owner->id,
                'refund_reference' => 'POSREF'.Str::upper(Str::random(10)),
                'idempotency_key' => 'manual-refund:'.$sale->id.':'.Str::uuid(),
                'amount' => $amount->__toString(),
                'status' => 'recorded',
                'reason' => $data['reason'],
                'recorded_at' => now(),
            ]);
            $refundTotal = $alreadyRefunded->plus($amount);
            $sale->update([
                'payment_status' => $refundTotal->isEqualTo((string) $payment->amount) ? 'refunded' : 'partially_refunded',
                'updated_by' => $owner->id,
            ]);
            $this->audit($owner, 'order_manual_refund_recorded', $sale, [
                'refund_reference' => $refund->refund_reference,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'manual_only' => true,
            ]);
        });

        return back()->with('status', 'Manual refund recorded. No funds were transferred by TastyBite.');
    }

    public function export(Request $request)
    {
        $owner = $this->owner();
        [$start, $end] = $this->dateRange($request);
        $orders = $this->dateScopedOrders($owner->restaurant_id, $start, $end)
            ->withCount('items')->orderBy('created_at')->cursor();
        $filename = 'orders-'.$start.'-to-'.$end.'.csv';

        return response()->streamDownload(function () use ($orders): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Order Number', 'Created At', 'Type', 'Status', 'Payment Status', 'Subtotal', 'Discount', 'Tax', 'Total', 'Items']);
            foreach ($orders as $order) {
                fputcsv($output, array_map($this->safeCsvCell(...), [
                    $order->order_number,
                    $order->created_at?->toDateTimeString(),
                    $order->order_type,
                    $order->fulfillment_status,
                    $order->payment_status,
                    $order->subtotal,
                    $order->discount,
                    $order->tax,
                    $order->total,
                    (string) $order->items_count,
                ]));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function listing(Request $request, string $kind): View|JsonResponse
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'ready', 'served', 'dispatched', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'paid', 'partially_refunded', 'refunded'])],
            'order_type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'upi'])],
            'table' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $title = match ($kind) {
            'dine_in' => 'Dine-In Orders',
            'takeaway', 'delivery' => 'Takeaway & Delivery',
            'billing' => 'Billing & Payments',
            default => 'All Orders',
        };
        $query = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
            ->where('status', '!=', 'draft')
            ->with(['items', 'creator', 'payments', 'refunds', 'diningFloor', 'diningSection', 'diningTable'])
            ->withCount('items')
            ->when($kind === 'dine_in', fn (Builder $builder) => $builder->where('order_type', 'dine_in'))
            ->when(in_array($kind, ['takeaway', 'delivery'], true), fn (Builder $builder) => $builder->where('order_type', $kind))
            ->when(($filters['search'] ?? '') !== '', fn (Builder $builder) => $builder->where('order_number', 'like', '%'.trim($filters['search']).'%'))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $value) => $builder->where('fulfillment_status', $value))
            ->when($filters['payment_status'] ?? null, fn (Builder $builder, string $value) => $builder->where('payment_status', $value))
            ->when(($filters['order_type'] ?? null) && ! in_array($kind, ['dine_in', 'takeaway', 'delivery'], true), fn (Builder $builder) => $builder->where('order_type', $filters['order_type']))
            ->when($filters['payment_method'] ?? null, fn (Builder $builder, string $value) => $builder->where('payment_method', $value))
            ->when($filters['table'] ?? null, fn (Builder $builder, string $value) => $builder->where('table_number', $value))
            ->when($filters['from'] ?? null, fn (Builder $builder, string $value) => $builder->whereDate('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $builder, string $value) => $builder->whereDate('created_at', '<=', $value))
            ->latest('created_at');
        $orders = $query->paginate(15)->withQueryString();
        $viewData = [
            'owner' => $owner,
            'restaurant' => $owner->restaurant,
            'orders' => $orders,
            'filters' => $filters,
            'kind' => $kind,
            'title' => $title,
            'adminMode' => app(RestaurantAccessContext::class)->isSuperAdminAccess(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'html' => view('restaurant.orders.partials.order-list', $viewData)->render(),
            ]);
        }

        return view('restaurant.orders.list', $viewData);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period' => ['nullable', Rule::in(['today', 'yesterday', '7', '30', 'custom'])],
        ]);
        $period = $data['period'] ?? (isset($data['from']) || isset($data['to']) ? 'custom' : 'today');
        $timezone = config('app.timezone');
        $today = CarbonImmutable::now($timezone)->toDateString();
        if ($period === 'custom') {
            return [$data['from'] ?? $today, $data['to'] ?? $data['from'] ?? $today];
        }
        if ($period === 'yesterday') {
            $yesterday = CarbonImmutable::now($timezone)->subDay()->toDateString();

            return [$yesterday, $yesterday];
        }
        if ($period === '7') {
            return [CarbonImmutable::now($timezone)->subDays(6)->toDateString(), $today];
        }
        if ($period === '30') {
            return [CarbonImmutable::now($timezone)->subDays(29)->toDateString(), $today];
        }

        return [$today, $today];
    }

    private function dateScopedOrders(int $restaurantId, string $start, string $end): Builder
    {
        return RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
            ->where('status', '!=', 'draft')
            ->whereBetween('created_at', $this->localDateBounds($start, $end));
    }

    /**
     * Convert inclusive calendar dates into the app-timezone wall-clock interval
     * used by Laravel's timezone-naive SQL timestamps.
     *
     * @return array{0: string, 1: string}
     */
    private function localDateBounds(string $start, string $end): array
    {
        $timezone = config('app.timezone');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $start, $timezone);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $end, $timezone)->addDay()->subSecond();

        return [$from->toDateTimeString(), $until->toDateTimeString()];
    }

    /**
     * @return array{gross: float, refunds: float, net: float}
     */
    private function paymentSummary(int $restaurantId, string $start, string $end): array
    {
        $dateRange = $this->localDateBounds($start, $end);
        $gross = BigDecimal::of((string) (DB::table('restaurant_pos_payments as payments')
            ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'payments.pos_order_id')
            ->where('payments.restaurant_id', $restaurantId)
            ->where('orders.restaurant_id', $restaurantId)
            ->where('payments.status', 'recorded')
            ->whereBetween('payments.recorded_at', $dateRange)
            ->sum('payments.amount') ?? '0.00'))->toScale(2);
        $refunds = BigDecimal::of((string) (DB::table('restaurant_pos_refunds as refunds')
            ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'refunds.pos_order_id')
            ->where('refunds.restaurant_id', $restaurantId)
            ->where('orders.restaurant_id', $restaurantId)
            ->where('refunds.status', 'recorded')
            ->whereBetween('refunds.recorded_at', $dateRange)
            ->sum('refunds.amount') ?? '0.00'))->toScale(2);
        $net = $gross->minus($refunds);

        return [
            'gross' => (float) $gross->__toString(),
            'refunds' => (float) $refunds->__toString(),
            'net' => (float) $net->toScale(2)->__toString(),
        ];
    }

    private function paymentBreakdown(int $restaurantId, string $start, string $end): Collection
    {
        return DB::table('restaurant_pos_payments as payments')
            ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'payments.pos_order_id')
            ->where('payments.restaurant_id', $restaurantId)
            ->where('orders.restaurant_id', $restaurantId)
            ->where('payments.status', 'recorded')
            ->whereBetween('payments.recorded_at', $this->localDateBounds($start, $end))
            ->select('payments.method as payment_method', DB::raw('COUNT(DISTINCT orders.id) as total'), DB::raw('SUM(payments.amount) as sales'))
            ->groupBy('payments.method')
            ->get();
    }

    private function tenantOrder(User $owner, int $order): RestaurantPosOrder
    {
        return RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($order);
    }

    private function writeStatusHistory(
        RestaurantPosOrder $order,
        User $owner,
        string $from,
        string $to,
        ?string $reason,
    ): void {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        RestaurantPosOrderStatusHistory::create([
            'restaurant_id' => $owner->restaurant_id,
            'pos_order_id' => $order->id,
            'actor_id' => $admin instanceof SuperAdmin ? null : $owner->id,
            'actor_type' => $admin instanceof SuperAdmin ? 'super_admin' : 'restaurant_user',
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    private function audit(User $owner, string $action, RestaurantPosOrder $order, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        RestaurantAuditLog::create([
            'actor_id' => $admin instanceof SuperAdmin ? $admin->id : null,
            'actor_type' => $admin instanceof SuperAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => $owner->restaurant_id,
            'action' => $action,
            'target_type' => 'restaurant_pos_order',
            'target_id' => $order->id,
            'details' => $details + ['order_number' => $order->order_number],
        ]);
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }

    private function releaseDiningTable(int $actorId, RestaurantPosOrder $sale): void
    {
        $occupancies = RestaurantTableOccupancy::where('restaurant_id', $sale->restaurant_id)
            ->where('pos_order_id', $sale->id)->whereNull('ended_at')->orderBy('table_id')->lockForUpdate()->get();
        if ($occupancies->isEmpty()) {
            return;
        }
        foreach ($occupancies as $occupancy) {
            $table = RestaurantTable::where('restaurant_id', $sale->restaurant_id)->lockForUpdate()->find($occupancy->table_id);
            if (! $table) {
                continue;
            }
            $occupancy->update(['ended_at' => now(), 'ended_by' => $actorId]);
            RestaurantTableStatusHistory::create([
                'restaurant_id' => $sale->restaurant_id, 'table_id' => $table->id,
                'actor_id' => $actorId, 'actor_type' => 'restaurant_user',
                'from_status' => $table->status, 'to_status' => 'cleaning',
                'reason' => 'Dine-in order fulfillment completed', 'changed_at' => now(),
            ]);
            $table->update(['status' => 'cleaning']);
        }
        $groupIds = $occupancies->pluck('group_id')->filter()->unique();
        if ($groupIds->isNotEmpty()) {
            \App\Models\RestaurantTableGroup::where('restaurant_id', $sale->restaurant_id)->whereIn('id', $groupIds)->whereNull('ended_at')->update(['ended_at' => now()]);
        }
    }
}
