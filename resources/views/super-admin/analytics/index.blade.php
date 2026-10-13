@extends('layouts.super-admin')

@section('title', 'Analytics')
@section('section', 'Analytics')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">PLATFORM ANALYTICS</span>
            <h1>Analytics</h1>
            <p>Growth, subscription mix, and recorded payment trends from platform records.</p>
        </div>
        <a class="button button-secondary" href="{{ route('super-admin.reports.index') }}">Detailed reports</a>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.analytics.index') }}" class="toolbar-actions" style="flex:1;">
            <label class="form-field"><span>From</span><input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] }}" required></label>
            <label class="form-field"><span>To</span><input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] }}" required></label>
            <label class="form-field"><span>Data scope</span>
                <select class="toolbar-search" name="scope">
                    @foreach (['real' => 'Real records only', 'demo' => 'Demo records only', 'all' => 'All records'] as $scope => $label)
                        <option value="{{ $scope }}" @selected($filters['scope'] === $scope)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button button-secondary" type="submit">Apply</button>
        </form>
    </div>

    <div class="report-scope-note"><strong>Scope: {{ ucfirst($filters['scope']) }}.</strong> Trends use the selected date range. Revenue is approved and refunded payment record value, shown separately by currency without conversion; it is not confirmation of funds received.</div>

    <section class="summary-row report-summary" aria-label="Platform analytics summary">
        <article class="summary-tile"><span>New restaurants</span><strong>{{ number_format($summary['new_restaurants']) }}</strong><small>{{ number_format($summary['previous_restaurants']) }} in the previous equal-length period</small></article>
        <article class="summary-tile"><span>New subscriptions</span><strong>{{ number_format($summary['new_subscriptions']) }}</strong><small>{{ number_format($summary['previous_subscriptions']) }} in the previous equal-length period</small></article>
        <article class="summary-tile"><span>Currently active subscriptions</span><strong>{{ number_format($summary['active_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Subscription cancellations in period</span><strong>{{ number_format($summary['cancelled_subscriptions']) }}</strong></article>
    </section>

    <div class="section-heading"><div><h2>Restaurant growth</h2><p>Restaurant accounts created during the selected range, grouped by month.</p></div></div>
    <section class="report-chart" aria-label="Restaurant growth by month">
        @php($maxRestaurants = max(1, (int) $restaurantTrend->max('total')))
        @forelse ($restaurantTrend as $month)
            @php($height = max(3, (int) round(((int) $month->total / $maxRestaurants) * 100)))
            <div class="report-chart-column" title="{{ $month->period }} · {{ $month->total }} restaurants">
                <span>{{ number_format($month->total) }}</span><div><i style="height: {{ $height }}%"></i></div><small>{{ $month->period }}</small>
            </div>
        @empty
            <div class="empty-state"><h3>No restaurant growth in this range</h3><p>Change the date range or data scope to view recorded account creation.</p></div>
        @endforelse
    </section>

    <div class="section-heading"><div><h2>Recorded revenue by currency</h2><p>Approved payment totals less recorded refunds, grouped by payment date.</p></div></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Currency</th><th>Gross approved</th><th>Refunds recorded</th><th>Net recorded</th></tr></thead>
            <tbody>
                @forelse ($revenue as $row)
                    <tr><td><strong>{{ $row['currency'] }}</strong></td><td>{{ $row['gross'] }}</td><td>{{ $row['refunds'] }}</td><td><strong>{{ $row['net'] }}</strong></td></tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state"><h3>No approved payment records in this range</h3><p>Pending and rejected payments are not included in collection totals.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="report-grid" style="margin-top:28px;">
        <section>
            <div class="section-heading"><div><h2>Payment status breakdown</h2><p>Payment records with dates in the selected range.</p></div></div>
            <div class="table-shell">
                <table class="data-table">
                    <thead><tr><th>Status</th><th>Records</th></tr></thead>
                    <tbody>
                        @forelse ($paymentStatuses as $status)
                            <tr><td>{{ ucfirst($status->status) }}</td><td>{{ number_format($status->total) }}</td></tr>
                        @empty
                            <tr><td colspan="2"><div class="empty-state"><h3>No payment records</h3><p>No dated payments match this range.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section>
            <div class="section-heading"><div><h2>Current active plan mix</h2><p>Valid active and trial subscriptions, independent of the selected date range.</p></div></div>
            <div class="table-shell">
                <table class="data-table">
                    <thead><tr><th>Plan</th><th>Subscriptions</th></tr></thead>
                    <tbody>
                        @forelse ($activePlanMix as $plan)
                            <tr><td>{{ $plan->plan_name }}</td><td>{{ number_format($plan->total) }}</td></tr>
                        @empty
                            <tr><td colspan="2"><div class="empty-state"><h3>No active subscriptions</h3><p>No valid active or trial subscriptions match this scope.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
