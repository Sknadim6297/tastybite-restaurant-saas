@extends('layouts.restaurant-admin')

@section('title', 'Order Overview')
@section('section', 'Orders Management')

@section('content')
    <div class="page-heading orders-page-heading">
        <div><span class="eyebrow">ORDERS MANAGEMENT</span><h1>Order Overview</h1><p>Live order, sales and payment summaries for your restaurant.</p></div>
        <div class="orders-heading-actions">
            <a class="button button-secondary" href="{{ route('restaurant.orders.all') }}">All orders</a>
            <a class="button button-primary" href="{{ route('pos.index') }}">Open POS Billing</a>
        </div>
    </div>

    <form class="panel-card order-date-filter" method="GET" action="{{ route('restaurant.orders.overview') }}">
        <div class="form-field">
            <label for="overview-period">Date range</label>
            <select id="overview-period" name="period">
                <option value="today" @selected(request('period', 'today') === 'today')>Today</option>
                <option value="yesterday" @selected(request('period') === 'yesterday')>Yesterday</option>
                <option value="7" @selected(request('period') === '7')>Last 7 days</option>
                <option value="30" @selected(request('period') === '30')>Last 30 days</option>
                <option value="custom" @selected(request('period') === 'custom')>Custom range</option>
            </select>
        </div>
        <div class="form-field"><label for="overview-from">From</label><input id="overview-from" type="date" name="from" value="{{ request('from', $startDate) }}"></div>
        <div class="form-field"><label for="overview-to">To</label><input id="overview-to" type="date" name="to" value="{{ request('to', $endDate) }}"></div>
    </form>
    <script src="{{ asset('assets/order-date-filters.js') }}?v={{ filemtime(public_path('assets/order-date-filters.js')) }}" defer></script>

    <section class="order-kpi-grid" aria-label="Order and sales metrics">
        @foreach([
            ['Orders in range', $metrics['total'], 'bi-receipt'],
            ['Pending', $metrics['pending'], 'bi-hourglass-split'],
            ['Preparing', $metrics['preparing'], 'bi-fire'],
            ['Ready', $metrics['ready'], 'bi-check2-square'],
            ['Completed', $metrics['completed'], 'bi-check-circle'],
            ['Cancelled', $metrics['cancelled'], 'bi-x-circle'],
            ['Gross sales', '₹'.number_format((float) $metrics['gross'], 2), 'bi-cash-stack'],
            ['Discounts', '₹'.number_format((float) $metrics['discounts'], 2), 'bi-percent'],
            ['Tax', '₹'.number_format((float) $metrics['tax'], 2), 'bi-receipt-cutoff'],
            ['Net sales', '₹'.number_format((float) $metrics['net'], 2), 'bi-wallet2'],
            ['Unpaid orders', $metrics['unpaid'], 'bi-exclamation-circle'],
            ['Net paid revenue', '₹'.number_format((float) $metrics['paidRevenue'], 2), 'bi-wallet'],
            ['Average order value', '₹'.number_format((float) $metrics['average'], 2), 'bi-graph-up-arrow'],
        ] as [$label, $value, $icon])
            <article class="order-kpi-card"><span class="order-kpi-icon"><i class="bi {{ $icon }}" aria-hidden="true"></i></span><span class="order-kpi-label">{{ $label }}</span><strong>{{ $value }}</strong></article>
        @endforeach
    </section>

    <section class="orders-dashboard-grid">
        <article class="panel-card orders-dashboard-card">
            <div class="orders-section-heading"><div><h2>Sales trend</h2><p>Completed sales by day in the selected period.</p></div></div>
            @if($salesTrend->isEmpty())
                <div class="empty-state"><h3>No sales in this period</h3><p>Completed POS sales will appear here.</p></div>
            @else
                <div class="sales-trend-list">
                    @php($maxSales = max(1, (float) $salesTrend->max('total')))
                    @foreach($salesTrend as $day)
                        <div class="sales-trend-row"><span>{{ \Carbon\Carbon::parse($day->day)->format('M j') }}</span><div><i style="width: {{ max(2, ((float) $day->total / $maxSales) * 100) }}%"></i></div><strong>₹{{ number_format((float) $day->total, 2) }}</strong></div>
                    @endforeach
                </div>
            @endif
        </article>
        <article class="panel-card orders-dashboard-card">
            <div class="orders-section-heading"><div><h2>Order breakdown</h2><p>Current order status and order type.</p></div></div>
            <div class="breakdown-columns">
                <div><h3>Status</h3>@forelse($statusBreakdown as $status => $count)<p><span>{{ str($status)->replace('_', ' ')->title() }}</span><strong>{{ number_format((int) $count) }}</strong></p>@empty<p class="muted-copy">No orders in this period.</p>@endforelse</div>
                <div><h3>Type</h3>@forelse($typeBreakdown as $type => $count)<p><span>{{ str($type)->replace('_', ' ')->title() }}</span><strong>{{ $count }}</strong></p>@empty<p class="muted-copy">No orders in this period.</p>@endforelse</div>
            </div>
            <div class="payment-breakdown"><h3>Recorded payment methods</h3>@forelse($paymentBreakdown as $row)<p><span>{{ strtoupper($row->payment_method) }}</span><strong>{{ $row->total }}</strong></p>@empty<p class="muted-copy">No payment records in this period.</p>@endforelse</div>
        </article>
    </section>

    <section class="panel-card recent-orders-card">
        <div class="orders-section-heading"><div><h2>Recent orders</h2><p>Latest order activity in the selected date range.</p></div><a class="button button-secondary" href="{{ route('restaurant.orders.all') }}">View all</a></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Time</th><th>Type</th><th>Items</th><th>Total</th><th>Payment</th><th>Fulfillment</th><th></th></tr></thead>
                <tbody>
                    @forelse($recentOrders as $order)
                        <tr><td><strong>{{ $order->order_number }}</strong></td><td>{{ $order->created_at?->format('M j, H:i') }}</td><td>{{ str($order->order_type)->replace('_', ' ')->title() }}</td><td>{{ $order->items_count }}</td><td>₹{{ number_format((float) $order->total, 2) }}</td><td>{{ str($order->payment_status)->replace('_', ' ')->title() }}</td><td><span class="order-status-badge order-status-{{ $order->fulfillment_status }}">{{ str($order->fulfillment_status)->replace('_', ' ')->title() }}</span></td><td><a class="button button-secondary" href="{{ route('restaurant.orders.show', $order) }}">View</a></td></tr>
                    @empty
                        <tr><td colspan="8"><div class="empty-state"><h3>No orders in this period</h3><p>Orders created through POS Billing will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
