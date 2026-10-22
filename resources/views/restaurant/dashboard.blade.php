@extends('layouts.restaurant-admin')

@section('title', 'Dashboard')
@section('section', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">RESTAURANT OVERVIEW</span>
            @if (request()->attributes->has('restaurant_admin_access_restaurant'))
                <h1>Good to see you, Super Admin.</h1>
            @else
                <h1>Good to see you, {{ Str::of($user->name)->before(' ') }}.</h1>
            @endif
            <p>{{ $restaurant->name ?? 'Your restaurant' }} subscription and account overview.</p>
        </div>
        <span class="date-chip">{{ now()->format('l, j M Y') }}</span>
    </div>

    @if ($approvalStatus !== 'approved')
        <div class="notice-card" role="status">
            <strong>
                @if ($approvalStatus === 'pending')
                    Restaurant approval is pending
                @elseif ($approvalStatus === 'information_requested')
                    More information is required
                @else
                    Restaurant approval was rejected
                @endif
            </strong>
            <p>
                @if ($approvalNotice?->reason)
                    {{ $approvalNotice->reason }}
                @else
                    Operational features remain unavailable until the approval status is resolved.
                @endif
            </p>
            @if ($approvalStatus === 'information_requested')
                <a href="{{ route('restaurant.support.index') }}">Contact platform support</a>
            @endif
        </div>
    @endif

    <section class="welcome-card" aria-labelledby="welcome-title">
        <div class="welcome-copy">
            <span class="welcome-kicker">RESTAURANT ADMIN WORKSPACE</span>
            <h2 id="welcome-title">Your restaurant starts here.</h2>
            <p>Power your service operations through a secure admin dashboard. The next modules will go live as the restaurant management stack is expanded.</p>
            @unless (request()->attributes->has('restaurant_admin_access_restaurant'))
                <a class="button button-secondary" href="{{ route('restaurant.profile') }}">Review your profile <span aria-hidden="true">→</span></a>
            @endunless
        </div>
        <div class="welcome-art" aria-hidden="true"><span class="welcome-art-dot"></span></div>
    </section>

    <section class="section-heading">
        <div>
            <h2>Subscription</h2>
            <p>Plan and access dates currently recorded for this restaurant.</p>
        </div>
        @unless (request()->attributes->has('restaurant_admin_access_restaurant'))
            <a class="button button-secondary" href="{{ route('restaurant.subscription') }}">View subscription</a>
        @endunless
    </section>
    <section class="subscription-overview" aria-label="Current subscription">
        @if ($subscription)
            <div><span class="meta-label">CURRENT PLAN</span><strong>{{ $subscription->plan_name }}</strong></div>
            <div><span class="meta-label">STATUS</span><strong class="status-pill status-{{ $subscriptionStatus }}">{{ ucfirst($subscriptionStatus) }}</strong></div>
            <div><span class="meta-label">START DATE</span><strong>{{ $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
            <div><span class="meta-label">EXPIRY DATE</span><strong>{{ $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
            <div><span class="meta-label">DAYS REMAINING</span><strong>{{ $daysRemaining }}</strong></div>
        @else
            <div class="empty-state subscription-empty">
                <h3>No subscription assigned</h3>
                <p>Contact the TastyBite platform administrator for plan and renewal information.</p>
            </div>
        @endif
    </section>

    <div class="section-heading">
        <div><h2>Operational modules</h2><p>Modules in progress for the restaurant stack.</p></div>
    </div>
    <section class="module-grid" aria-label="Restaurant admin modules coming soon">
        <article class="module-card"><span class="module-icon" aria-hidden="true">▣</span><div><h3>Today's Sales</h3><p>Revenue visibility for the current service day.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">◫</span><div><h3>Today's Orders</h3><p>Live orders and order processing queue.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">♨</span><div><h3>Pending KOT</h3><p>Early kitchen and service coordination.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">▤</span><div><h3>Available Tables</h3><p>Table occupancy and floor optimisation.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">◍</span><div><h3>Low Stock Alerts</h3><p>Inventory warnings and replenishment readiness.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">♙</span><div><h3>Staff Overview</h3><p>Service staffing and task visibility.</p></div><span class="coming-soon">Coming soon</span></article>
        <article class="module-card"><span class="module-icon" aria-hidden="true">◇</span><div><h3>Subscription Status</h3><p>Current plan and access status.</p></div><span class="coming-soon">Coming soon</span></article>
    </section>
@endsection
