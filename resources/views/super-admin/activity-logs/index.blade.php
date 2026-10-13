@extends('layouts.super-admin')

@section('title', 'Activity Logs')
@section('section', 'Activity Logs')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">SECURITY & GOVERNANCE</span><h1>Activity logs</h1><p>Read-only history of administrative changes and authentication activity.</p></div><a class="button button-secondary" href="{{ route('super-admin.activity-logs.export', request()->query()) }}">Export filtered CSV</a></div>
    <form method="GET" class="form-card report-filters">
        <label>Search<input name="search" maxlength="150" value="{{ $filters['search'] ?? '' }}" placeholder="Action, record, actor, IP"></label>
        <label>Module<select name="module"><option value="">All modules</option>@foreach($modules as $key => $label)<option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>Administrator<select name="actor_id"><option value="">All administrators</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected((string) ($filters['actor_id'] ?? '') === (string) $admin->id)>{{ $admin->name }}</option>@endforeach</select></label>
        <label>Restaurant<select name="restaurant_id"><option value="">All restaurants</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select></label>
        <label>Action<input name="action" maxlength="100" value="{{ $filters['action'] ?? '' }}" placeholder="Exact action name"></label>
        <label>From date<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label>To date<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <div class="report-filter-actions"><button class="button button-primary">Apply filters</button><a class="button button-secondary" href="{{ route('super-admin.activity-logs.index') }}">Clear</a></div>
    </form>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Date & time</th><th>Administrator</th><th>Module</th><th>Action</th><th>Restaurant</th><th>IP address</th><th></th></tr></thead><tbody>
        @forelse($logs as $log)
            <tr><td>{{ $log->created_at?->format(config('app.date_format', 'M j, Y').' · g:i:s A') }}</td><td>{{ $log->actor?->name ?? 'System / unavailable' }}<small>{{ $log->actor_type }}</small></td><td>{{ $modules[$log->target_type] ?? 'Platform' }}</td><td>{{ str($log->action)->replace('_', ' ')->title() }}</td><td>{{ $log->restaurant?->name ?? '—' }}</td><td>{{ $log->ip_address ?? '—' }}</td><td><a class="table-primary-link" href="{{ route('super-admin.activity-logs.show', $log) }}">Details</a></td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No activity matches these filters</h3><p>Try a wider date range or clear some filters.</p></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-wrap">{{ $logs->links() }}</div>
@endsection
