@extends('layouts.super-admin')

@section('title', 'Subscription Report')
@section('section', 'Reports')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PLATFORM INSIGHTS</span><h1>Subscription report</h1><p>Review plan assignments, lifecycle status and expiry dates.</p></div><a class="button button-secondary" href="{{ route('super-admin.reports.export', array_merge(request()->query(), ['type' => 'subscriptions'])) }}">Export CSV</a></div>
    @include('super-admin.reports._tabs', ['active' => 'subscriptions'])
    <form method="GET" class="form-card report-filters" data-auto-filter>
        <label>Data scope<select name="scope"><option value="real" @selected($filters['scope'] === 'real')>Real records</option><option value="demo" @selected($filters['scope'] === 'demo')>Demo records</option><option value="all" @selected($filters['scope'] === 'all')>All records</option></select></label>
        <label>Status<select name="subscription_status"><option value="">All statuses</option>@foreach(['pending_payment','pending_approval','scheduled','active','trial','expired','suspended','cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['subscription_status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Plan<select name="plan_id"><option value="">Any plan</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string) ($filters['plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name }}</option>@endforeach</select></label>
        <label>Expiring in days<input type="number" min="1" max="365" name="expiring_days" value="{{ $filters['expiring_days'] ?? '' }}"></label>
        <label>Starts from<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label>Starts to<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <div class="report-filter-actions"><a class="button button-secondary" href="{{ route('super-admin.reports.subscriptions') }}" data-auto-filter-reset>Clear</a></div>
    </form>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Restaurant</th><th>Plan</th><th>Price</th><th>Status</th><th>Start date</th><th>Expiry date</th><th>Events</th></tr></thead><tbody>
        @forelse($subscriptions as $subscription)
            <tr><td><a class="table-primary-link" href="{{ route('super-admin.restaurants.show', $subscription->restaurant) }}">{{ $subscription->restaurant->name }}</a></td><td>{{ $subscription->plan_name }}</td><td>{{ $subscription->currency }} {{ $subscription->price }}</td>
                <td><span class="status-pill status-{{ $subscription->effectiveStatus() }}">{{ ucfirst($subscription->effectiveStatus()) }}</span></td><td>{{ $subscription->starts_at?->format(config('app.date_format', 'M j, Y')) }}</td><td>{{ $subscription->expires_at?->format(config('app.date_format', 'M j, Y')) }}</td><td>{{ $subscription->events->count() }}</td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No subscriptions found</h3><p>Adjust filters or select a different data scope.</p></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-wrap">{{ $subscriptions->links() }}</div>
@endsection
