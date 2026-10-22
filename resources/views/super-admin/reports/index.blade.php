@extends('layouts.super-admin')

@section('title', 'Platform Reports')
@section('section', 'Reports')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PLATFORM INSIGHTS</span><h1>Reports</h1><p>Current platform totals are derived from recorded restaurants, subscriptions, payments and refunds.</p></div>
    </div>
    <div class="report-tabs" aria-label="Report sections">
        <a class="is-active" href="{{ route('super-admin.reports.index') }}">Overview</a>
        <a href="{{ route('super-admin.reports.restaurants') }}">Restaurants</a>
        <a href="{{ route('super-admin.reports.subscriptions') }}">Subscriptions</a>
        <a href="{{ route('super-admin.reports.payments') }}">Payments</a>
        <a href="{{ route('super-admin.reports.revenue') }}">Revenue</a>
    </div>
    <form method="GET" class="form-card report-filters">
        <label>Data scope
            <select name="scope" onchange="this.form.submit()">
                <option value="real" @selected($scope === 'real')>Real records</option>
                <option value="demo" @selected($scope === 'demo')>Demo records</option>
                <option value="all" @selected($scope === 'all')>All records</option>
            </select>
        </label>
        <noscript><button class="button button-secondary" type="submit">Apply scope</button></noscript>
    </form>
    <div class="section-heading">
        <div>
            <h2>{{ ucfirst($scope) }} records</h2>
            <p>
                @if($scope === 'real')
                    Demo seed records are excluded from these totals.
                @elseif($scope === 'demo')
                    Only records explicitly marked as demo are included.
                @else
                    Real and marked demo records are combined; demo revenue remains labeled as synthetic in detailed reports.
                @endif
            </p>
        </div>
    </div>
    <section class="summary-row report-summary">
        <article class="summary-tile"><span>Restaurants</span><strong>{{ number_format($counts[$scope]['restaurants']) }}</strong><small>{{ number_format($counts[$scope]['active_restaurants']) }} active</small></article>
        <article class="summary-tile"><span>Active restaurant owners</span><strong>{{ number_format($counts[$scope]['owners']) }}</strong></article>
        <article class="summary-tile"><span>Active subscriptions</span><strong>{{ number_format($counts[$scope]['active_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Pending payments</span><strong>{{ number_format($counts[$scope]['pending_payments']) }}</strong></article>
        <article class="summary-tile"><span>Expiring within 30 days</span><strong>{{ number_format($counts[$scope]['expiring_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Expired subscriptions</span><strong>{{ number_format($counts[$scope]['expired_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Gross recorded revenue</span><strong>INR {{ $counts[$scope]['gross'] }}</strong></article>
        <article class="summary-tile"><span>Refunds recorded</span><strong>INR {{ $counts[$scope]['refunds'] }}</strong></article>
        <article class="summary-tile"><span>Net recorded collections</span><strong>INR {{ $counts[$scope]['net'] }}</strong></article>
    </section>
    <div class="section-heading"><div><h2>12-month revenue trend</h2><p>{{ ucfirst($scope) }} approved payments less recorded refunds, grouped by payment date.</p></div><a class="text-link" href="{{ route('super-admin.reports.revenue', ['scope' => $scope]) }}">View revenue report →</a></div>
    <section class="report-chart" aria-label="Monthly net revenue">
        @php($maxRevenue = max(array_map(fn ($month) => (float) $month['net'], $months) ?: [0.01]))
        @foreach($months as $month)
            @php($height = $maxRevenue > 0 ? max(3, (int) round(((float) $month['net'] / $maxRevenue) * 100)) : 3)
            <div class="report-chart-column" title="{{ $month['label'] }} · INR {{ $month['net'] }}">
                <span>INR {{ $month['net'] }}</span><div><i style="height: {{ $height }}%"></i></div><small>{{ $month['label'] }}</small>
            </div>
        @endforeach
    </section>
    <div class="report-scope-note"><strong>Demo data is not real revenue.</strong> {{ number_format($counts['demo']['restaurants']) }} demo restaurants and {{ number_format($counts['demo']['active_subscriptions']) }} active demo subscriptions are available separately. The Demo Records scope is synthetic; only Real Records represent actual collections.</div>
@endsection
