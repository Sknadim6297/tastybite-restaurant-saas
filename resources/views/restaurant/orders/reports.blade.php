@extends('layouts.restaurant-admin')

@section('title', 'Order Reports')
@section('section', 'Orders Management')

@section('content')
    <div class="page-heading orders-page-heading">
        <div>
            <span class="eyebrow">ORDERS MANAGEMENT</span>
            <h1>Order Reports</h1>
            <p>Order totals follow order date. Collected revenue and refunds follow their recorded transaction dates.</p>
        </div>
        <a class="button button-secondary" href="{{ route('restaurant.orders.reports.export', request()->query()) }}">Export CSV</a>
    </div>

    <form class="panel-card order-date-filter" method="GET" action="{{ route('restaurant.orders.reports') }}">
        <div class="form-field">
            <label for="report-period">Date range</label>
            <select id="report-period" name="period">
                <option value="today" @selected(request('period', request('from') || request('to') ? 'custom' : 'today') === 'today')>Today</option>
                <option value="yesterday" @selected(request('period') === 'yesterday')>Yesterday</option>
                <option value="7" @selected(request('period') === '7')>Last 7 days</option>
                <option value="30" @selected(request('period') === '30')>Last 30 days</option>
                <option value="custom" @selected(request('period') === 'custom' || request('from') || request('to'))>Custom range</option>
            </select>
        </div>
        <div class="form-field">
            <label for="report-from">From</label>
            <input id="report-from" type="date" name="from" value="{{ request('from', request('period') === 'custom' ? $startDate : '') }}">
        </div>
        <div class="form-field">
            <label for="report-to">To</label>
            <input id="report-to" type="date" name="to" value="{{ request('to', request('period') === 'custom' ? $endDate : '') }}">
        </div>
    </form>
    <script src="{{ asset('assets/order-date-filters.js') }}?v={{ filemtime(public_path('assets/order-date-filters.js')) }}" defer></script>

    <section class="order-kpi-grid order-report-kpis" aria-label="Order and payment metrics">
        @foreach([
            ['total-orders', 'Total orders', number_format((int) $orderCount), 'order-date-metric'],
            ['completed-orders', 'Completed orders', number_format((int) $completedCount), 'order-date-metric'],
            ['cancelled-orders', 'Cancelled orders', number_format((int) $cancelledCount), 'order-date-metric'],
            ['completed-sales', 'Completed sales', '₹'.number_format((float) $salesTotal, 2), 'order-money-metric'],
            ['discounts', 'Discounts', '₹'.number_format((float) $discountTotal, 2), 'order-money-metric'],
            ['tax', 'Tax', '₹'.number_format((float) $taxTotal, 2), 'order-money-metric'],
            ['average-order-value', 'Average order value', '₹'.number_format((float) $averageOrderValue, 2), 'order-money-metric'],
            ['paid-revenue', 'Net paid revenue', '₹'.number_format((float) $paidRevenue, 2), 'order-money-metric'],
            ['unpaid-completed', 'Completed but unpaid', number_format((int) $unpaidCompletedCount), 'order-date-metric'],
        ] as [$metricKey, $label, $value, $metricType])
            <article class="order-kpi-card {{ $metricType }}" data-report-metric="{{ $metricKey }}">
                <span class="order-kpi-label">{{ $label }}</span>
                <strong>{{ $value }}</strong>
            </article>
        @endforeach
    </section>

    <section class="orders-dashboard-grid order-reports-grid">
        <article class="panel-card orders-dashboard-card order-report-card">
            <h2>Sales by order type</h2>
            <p class="order-report-description">Completed orders and their total value.</p>
            <div class="report-breakdown">
                @forelse($typeBreakdown as $row)
                    <p><span>{{ str($row->order_type)->replace('_', ' ')->title() }} · {{ number_format((int) $row->total) }} {{ (int) $row->total === 1 ? 'order' : 'orders' }}</span><strong>₹{{ number_format((float) $row->sales, 2) }}</strong></p>
                @empty
                    <p class="muted-copy">No completed orders in this period.</p>
                @endforelse
            </div>
        </article>
        <article class="panel-card orders-dashboard-card order-report-card">
            <h2>Orders by status</h2>
            <p class="order-report-description">Current order status; cancellations are not counted as completed.</p>
            <div class="report-breakdown">
                @forelse($statusBreakdown as $row)
                    <p><span>{{ str($row->order_status)->replace('_', ' ')->title() }}</span><strong>{{ number_format((int) $row->total) }}</strong></p>
                @empty
                    <p class="muted-copy">No orders in this period.</p>
                @endforelse
            </div>
        </article>
        <article class="panel-card orders-dashboard-card order-report-card">
            <h2>Recorded payment methods</h2>
            <p class="order-report-description">Confirmed payment records, grouped by transaction date.</p>
            <div class="report-breakdown">
                @forelse($paymentBreakdown as $row)
                    <p><span>{{ strtoupper($row->payment_method) }} · {{ number_format((int) $row->total) }} {{ (int) $row->total === 1 ? 'order' : 'orders' }}</span><strong>₹{{ number_format((float) $row->sales, 2) }}</strong></p>
                @empty
                    <p class="muted-copy">No confirmed payments in this period.</p>
                @endforelse
            </div>
        </article>
        <article class="panel-card orders-dashboard-card order-report-card">
            <h2>Top-selling products</h2>
            <p class="order-report-description">Items on completed, non-cancelled orders.</p>
            <div class="report-breakdown">
                @forelse($topProducts as $row)
                    <p><span>{{ $row->item_name }} · {{ number_format((int) $row->quantity) }} sold</span><strong>₹{{ number_format((float) $row->sales, 2) }}</strong></p>
                @empty
                    <p class="muted-copy">No completed items in this period.</p>
                @endforelse
            </div>
        </article>
    </section>
@endsection
