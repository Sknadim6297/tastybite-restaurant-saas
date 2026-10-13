@extends('layouts.super-admin')

@section('title', 'Restaurant Approvals')
@section('section', 'Restaurant Approvals')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">ONBOARDING REVIEW</span>
            <h1>Restaurant Approvals</h1>
            <p>Review provisioned restaurants before operational access is enabled.</p>
        </div>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ $pendingCount }}</strong><span>Pending review</span></div>
        <div class="summary-tile"><strong>{{ $informationCount }}</strong><span>Information requested</span></div>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.restaurant-approvals.index') }}" class="toolbar-actions" style="flex:1;">
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search restaurant or owner">
            <select class="toolbar-search" name="status" aria-label="Filter approval status" style="max-width:220px;">
                <option value="">Action required</option>
                @foreach (['pending', 'information_requested', 'approved', 'rejected'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
            <button class="button button-secondary" type="submit">Filter</button>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.restaurant-approvals.index') }}">Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr><th>Restaurant</th><th>Owner</th><th>Contact</th><th>Approval status</th><th>Submitted</th><th>History</th><th>Action</th></tr>
            </thead>
            <tbody>
                @forelse ($restaurants as $restaurant)
                    @php($owner = $restaurant->owners->first())
                    <tr>
                        <td><strong>{{ $restaurant->name }}</strong><br><span class="meta-label">{{ $restaurant->slug }}</span></td>
                        <td>{{ $owner?->name ?? 'No owner assigned' }}</td>
                        <td>{{ $owner?->email ?? $restaurant->email }}</td>
                        <td><span class="status-pill status-{{ $restaurant->approval_status === 'information_requested' ? 'pending' : $restaurant->approval_status }}">{{ ucfirst(str_replace('_', ' ', $restaurant->approval_status)) }}</span></td>
                        <td>{{ $restaurant->created_at->format('M j, Y') }}</td>
                        <td>{{ $restaurant->approval_history_count }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.restaurant-approvals.show', $restaurant) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No restaurants require review</h3><p>New restaurants marked pending will appear here. Approved restaurants retain operational access subject to subscription.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($restaurants->hasPages())
        <div class="page-tools pagination-tools">{{ $restaurants->links() }}</div>
    @endif
@endsection
