@extends('layouts.super-admin')

@section('title', 'Support Access')
@section('section', 'Support Access')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">CONTROLLED SUPPORT WORKFLOW</span><h1>Support Access</h1><p>Record, independently review and audit support-access requests.</p></div>
        <a class="button button-primary" href="{{ route('super-admin.support-access.create') }}">New access request</a>
    </div>

    <div class="notice-card" role="note"><strong>Tenant access is not implemented.</strong><p>This workflow records and approves support requests only. It does not impersonate an owner, switch guards, or provide access to restaurant data.</p></div>
    <div class="summary-row"><div class="summary-tile"><strong>{{ $pendingCount }}</strong><span>Pending independent review</span></div></div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.support-access.index') }}" class="toolbar-actions" style="flex:1;" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search restaurant, requester, scope or reason">
            <select class="toolbar-search" name="status" aria-label="Filter request status" style="max-width:180px;">
                <option value="">All statuses</option>
                @foreach (['pending', 'approved', 'denied', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.support-access.index') }}" data-auto-filter-reset>Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Restaurant</th><th>Requested by</th><th>Scope</th><th>Duration</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
                @forelse ($requests as $supportRequest)
                    <tr>
                        <td>{{ $supportRequest->restaurant->name }}</td>
                        <td>{{ $supportRequest->requester->name }}</td>
                        <td>{{ ucfirst($supportRequest->requested_scope) }}</td>
                        <td>{{ $supportRequest->requested_duration_minutes }} minutes</td>
                        <td><span class="status-pill status-{{ $supportRequest->status === 'approved' ? 'approved' : ($supportRequest->status === 'denied' ? 'rejected' : ($supportRequest->status === 'pending' ? 'pending' : 'disabled')) }}">{{ ucfirst($supportRequest->status) }}</span></td>
                        <td>{{ $supportRequest->created_at->format('M j, Y H:i') }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.support-access.show', $supportRequest) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No support-access requests</h3><p>Newly submitted requests will appear here for independent review.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="page-tools" style="margin-top:16px; justify-content:flex-end;">{{ $requests->links() }}</div>
    @endif
@endsection
