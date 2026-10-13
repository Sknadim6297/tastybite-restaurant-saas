@extends('layouts.super-admin')

@section('title', 'Restaurant Report')
@section('section', 'Reports')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PLATFORM INSIGHTS</span><h1>Restaurant report</h1><p>Filter restaurant records and export the matching result set.</p></div><a class="button button-secondary" href="{{ route('super-admin.reports.export', array_merge(request()->query(), ['type' => 'restaurants'])) }}">Export CSV</a></div>
    @include('super-admin.reports._tabs', ['active' => 'restaurants'])
    <form method="GET" class="form-card report-filters">
        <label>Data scope<select name="scope"><option value="real" @selected($filters['scope'] === 'real')>Real records</option><option value="demo" @selected($filters['scope'] === 'demo')>Demo records</option><option value="all" @selected($filters['scope'] === 'all')>All records</option></select></label>
        <label>Restaurant status<select name="status"><option value="">All statuses</option>@foreach(['active','suspended','inactive'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Plan<select name="plan_id"><option value="">Any plan</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string) ($filters['plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name }}</option>@endforeach</select></label>
        <label>Subscription status<select name="subscription_status"><option value="">Any status</option>@foreach(['active','trial','expired','suspended','cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['subscription_status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Registered from<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label>Registered to<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <div class="report-filter-actions"><button class="button button-primary">Apply filters</button><a class="button button-secondary" href="{{ route('super-admin.reports.restaurants') }}">Clear</a></div>
    </form>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Restaurant</th><th>Owner</th><th>Platform status</th><th>Current / latest plan</th><th>Subscription</th><th>Joined</th></tr></thead><tbody>
        @forelse($restaurants as $restaurant)
            @php($subscription = $restaurant->reportSubscription)
            <tr><td><a class="table-primary-link" href="{{ route('super-admin.restaurants.show', $restaurant) }}">{{ $restaurant->name }}</a><small>{{ $restaurant->email }}</small></td>
                <td>{{ $restaurant->owners->first()?->name ?? '—' }}<small>{{ $restaurant->owners->first()?->email ?? 'No owner' }}</small></td>
                <td><span class="status-pill status-{{ $restaurant->status }}">{{ ucfirst($restaurant->status) }}</span></td>
                <td>{{ $subscription?->plan_name ?? 'No subscription' }}</td><td>{{ $subscription?->effectiveStatus() ? ucfirst($subscription->effectiveStatus()) : '—' }}</td><td>{{ $restaurant->created_at?->format(config('app.date_format', 'M j, Y')) }}</td></tr>
        @empty<tr><td colspan="6"><div class="empty-state"><h3>No restaurants found</h3><p>Adjust filters or select a different data scope.</p></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-wrap">{{ $restaurants->links() }}</div>
@endsection
