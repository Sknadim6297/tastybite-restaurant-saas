@extends('layouts.super-admin')

@section('title', $plan->name)
@section('section', 'Subscription Plans')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PLAN DETAILS</span><h1>{{ $plan->name }}</h1><p>{{ $plan->code }} · {{ $plan->currency }} {{ number_format((float) $plan->price, 2) }}</p></div>
        <div class="action-stack">
            <a class="button button-secondary" href="{{ route('super-admin.subscription-plans.edit', $plan) }}">Edit plan</a>
            <a class="button button-primary" href="{{ route('super-admin.subscriptions.create', ['subscription_plan_id' => $plan->id]) }}">Assign to restaurant</a>
        </div>
    </div>
    <section class="panel-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Name / Code</span><strong>{{ $plan->name }} · {{ $plan->code }}</strong></div>
            <div class="detail-item"><span>Status</span><strong>{{ \App\Support\StatusPresenter::label($plan->status) }}</strong></div>
            <div class="detail-item"><span>Price</span><strong>{{ $plan->currency }} {{ number_format((float) $plan->price, 2) }}</strong></div>
            <div class="detail-item"><span>Billing period</span><strong>{{ ucfirst($plan->billing_period) }}@if($plan->billing_period === 'custom') ({{ $plan->duration_months ? $plan->duration_months.' months' : $plan->duration_days.' days' }})@endif</strong></div>
            <div class="detail-item"><span>Trial</span><strong>{{ $plan->trial_available ? $plan->trial_duration_days.' days' : 'Not available' }}</strong></div>
            <div class="detail-item"><span>Subscriptions assigned</span><strong>{{ $plan->subscriptions_count }}</strong></div>
            <div class="detail-item detail-item-wide"><span>Description</span><strong>{{ $plan->description ?: 'No description provided.' }}</strong></div>
        </div>
    </section>
    <div class="section-heading"><div><h2>Recent assignments</h2><p>Subscription records created with this plan.</p></div></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Restaurant</th><th>Start</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($plan->subscriptions as $subscription)
                    @php($effectiveStatus = $subscription->effectiveStatus())
                    <tr>
                        <td>{{ $subscription->restaurant?->name ?? 'Restaurant unavailable' }}</td>
                        <td>{{ $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td>{{ $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td><span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($effectiveStatus) }}">{{ \App\Support\StatusPresenter::label($effectiveStatus) }}</span></td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.subscriptions.show', $subscription) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><h3>No subscriptions assigned</h3><p>This plan has not been assigned to a restaurant.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
