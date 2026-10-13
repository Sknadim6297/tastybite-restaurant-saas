@extends('layouts.restaurant-admin')

@section('title', 'Subscription')
@section('section', 'Subscription')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT ACCESS</span><h1>Subscription status</h1><p>Subscription information for {{ $restaurant->name }}. No payment gateway is connected.</p></div>
        @if($subscriptionStatus)<span class="status-pill status-{{ $subscriptionStatus }}">{{ ucfirst($subscriptionStatus) }}</span>@endif
    </div>

    @if($subscription)
        <section class="panel-card">
            <div class="detail-grid">
                <div class="detail-item"><span>Current plan</span><strong>{{ $subscription->plan_name }}</strong></div>
                <div class="detail-item"><span>Plan code</span><strong>{{ $subscription->plan_code }}</strong></div>
                <div class="detail-item"><span>Status</span><strong>{{ ucfirst($subscriptionStatus) }}</strong></div>
                <div class="detail-item"><span>Billing terms</span><strong>{{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }} · {{ ucfirst($subscription->billing_period) }}</strong></div>
                <div class="detail-item"><span>Start date</span><strong>{{ $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
                <div class="detail-item"><span>Expiry date</span><strong>{{ $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
                <div class="detail-item"><span>Days remaining</span><strong>{{ $daysRemaining }}</strong></div>
            </div>
        </section>
    @else
        <section class="notice-card">
            <strong>No subscription has been assigned.</strong>
            <p>Contact the TastyBite platform administrator for plan and renewal information.</p>
        </section>
    @endif

    <section class="notice-card subscription-renewal-note">
        <strong>Renewal instructions</strong>
        <p>For renewals, plan changes, or subscription questions, contact your TastyBite platform administrator. Online payments are not available in this release.</p>
    </section>
    <div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.dashboard') }}">Back to dashboard</a><a class="button button-secondary" href="{{ route('restaurant.profile') }}">Account profile</a></div>
@endsection
