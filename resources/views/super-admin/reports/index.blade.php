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
    <div class="section-heading"><div><h2>Real platform records</h2><p>Demo seed records are excluded from these totals.</p></div></div>
    <section class="summary-row report-summary">
        <article class="summary-tile"><span>Restaurants</span><strong>{{ number_format($counts['real']['restaurants']) }}</strong><small>{{ number_format($counts['real']['active_restaurants']) }} active</small></article>
        <article class="summary-tile"><span>Active restaurant owners</span><strong>{{ number_format($counts['real']['owners']) }}</strong></article>
        <article class="summary-tile"><span>Active subscriptions</span><strong>{{ number_format($counts['real']['active_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Expiring within 30 days</span><strong>{{ number_format($counts['real']['expiring_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Expired subscriptions</span><strong>{{ number_format($counts['real']['expired_subscriptions']) }}</strong></article>
        <article class="summary-tile"><span>Net recorded collections</span><strong>INR {{ $counts['real']['net'] }}</strong><small>Gross INR {{ $counts['real']['gross'] }} · refunds INR {{ $counts['real']['refunds'] }}</small></article>
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
    <div class="report-scope-note"><strong>Demo data is not revenue.</strong> {{ number_format($counts['demo']['restaurants']) }} demo restaurants and {{ number_format($counts['demo']['active_subscriptions']) }} active demo subscriptions are available separately in the detailed reports. Demo payments are explicitly excluded from real collection totals.</div>
@endsection
