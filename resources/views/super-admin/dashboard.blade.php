@extends('layouts.super-admin')

@section('title', 'Dashboard')
@section('section', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">PLATFORM OVERVIEW</span>
            <h1>Good to see you, {{ \Illuminate\Support\Str::of($superAdmin->name)->before(' ') }}.</h1>
            <p>Your TastyBite platform control centre.</p>
        </div>
        <span class="date-chip">{{ now(config('app.timezone'))->format(config('app.date_format', 'M j, Y')) }}</span>
    </div>

    <section class="welcome-card" aria-labelledby="welcome-title">
        <div class="welcome-copy">
            <span class="welcome-kicker">SUPER ADMIN WORKSPACE</span>
            <h2 id="welcome-title">Your platform starts here.</h2>
            <p>Review platform activity, manage restaurant access, and keep subscriptions and payment records in view from one secure place.</p>
            <a class="button button-secondary" href="{{ route('super-admin.profile') }}">Review your profile <span aria-hidden="true">→</span></a>
        </div>
        <div class="welcome-art" aria-hidden="true"><span class="welcome-art-dot"></span></div>
    </section>

    <div class="section-heading">
        <div><h2>Platform modules</h2><p>Open a module to review current records and activity.</p></div>
    </div>
    <section class="module-grid" aria-label="Platform modules">
        <a class="module-card module-card-action" href="{{ route('super-admin.restaurants.index') }}"><span class="module-icon" aria-hidden="true">▦</span><div><h3>Restaurants</h3><p>Restaurant accounts and their platform access.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.restaurant-owners.index') }}"><span class="module-icon" aria-hidden="true">♙</span><div><h3>Restaurant Owners</h3><p>Owner accounts and account relationships.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.subscription-plans.index') }}"><span class="module-icon" aria-hidden="true">◇</span><div><h3>Subscription Plans</h3><p>Create plans and manage availability.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.features.index') }}"><span class="module-icon" aria-hidden="true">⚙</span><div><h3>Feature Management</h3><p>Configure feature access by subscription plan.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.subscriptions.index') }}"><span class="module-icon" aria-hidden="true">↻</span><div><h3>Restaurant Subscriptions</h3><p>Assign plans and manage subscription periods.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.payments.index') }}"><span class="module-icon" aria-hidden="true">₹</span><div><h3>Payments</h3><p>Manual subscription payment approvals and refund records.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.invoices.index') }}"><span class="module-icon" aria-hidden="true">▧</span><div><h3>Invoices &amp; Billing</h3><p>Payment receipts and linked refund records.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.reports.index') }}"><span class="module-icon" aria-hidden="true">▤</span><div><h3>Reports</h3><p>Platform and revenue reporting from actual records.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.analytics.index') }}"><span class="module-icon" aria-hidden="true">▤</span><div><h3>Analytics</h3><p>Growth, subscription mix and recorded payment trends.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.notifications.index') }}"><span class="module-icon" aria-hidden="true">♧</span><div><h3>Notifications</h3><p>Restaurant, subscription and payment events.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.activity-logs.index') }}"><span class="module-icon" aria-hidden="true">≡</span><div><h3>Activity Logs</h3><p>Read-only administrative audit history.</p></div><span class="coming-soon">Open</span></a>
        <a class="module-card module-card-action" href="{{ route('super-admin.settings.index') }}"><span class="module-icon" aria-hidden="true">⚙</span><div><h3>Settings</h3><p>Configure platform preferences and account security.</p></div><span class="coming-soon">Open</span></a>
    </section>
@endsection
