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
    <div class="section-heading">
        <div>
            <h2>Platform totals</h2>
            <p>Totals reflect active business records and exclude local development fixtures.</p>
        </div>
    </div>
    <section class="summary-row report-summary">
        <article class="summary-tile"><span>Restaurants</span><strong>{{ number_format($counts['restaurants']) }}</strong><small>{{ number_format($counts['active_restaurants']) }} active</small></article>
        <article class="summary-tile"><span>Active restaurant owners</span><strong>{{ number_format($counts['owners']) }}</strong></article>
        <article class="summary-tile"><span>Active subscriptions</span><strong>{{ number_format($counts['active_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Pending payments</span><strong>{{ number_format($counts['pending_payments']) }}</strong></article>
        <article class="summary-tile"><span>Expiring within 30 days</span><strong>{{ number_format($counts['expiring_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Expired subscriptions</span><strong>{{ number_format($counts['expired_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Gross recorded revenue</span><strong>INR {{ $counts['gross'] }}</strong></article>
        <article class="summary-tile"><span>Refunds recorded</span><strong>INR {{ $counts['refunds'] }}</strong></article>
        <article class="summary-tile"><span>Net recorded collections</span><strong>INR {{ $counts['net'] }}</strong></article>
    </section>
    <div class="section-heading"><div><h2>12-month revenue trend</h2><p>Approved payments less recorded refunds, grouped by payment date.</p></div><a class="text-link" href="{{ route('super-admin.reports.revenue') }}">View revenue report →</a></div>
    <section class="report-chart" aria-label="Monthly net revenue">
        @php($maxRevenue = max(array_map(fn ($month) => (float) $month['net'], $months) ?: [0.01]))
        @foreach($months as $month)
            @php($height = $maxRevenue > 0 ? max(3, (int) round(((float) $month['net'] / $maxRevenue) * 100)) : 3)
            <div class="report-chart-column" title="{{ $month['label'] }} · INR {{ $month['net'] }}">
                <span>INR {{ $month['net'] }}</span><div><i style="height: {{ $height }}%"></i></div><small>{{ $month['label'] }}</small>
            </div>
        @endforeach
    </section>
@endsection
