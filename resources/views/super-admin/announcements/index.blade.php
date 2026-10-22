@extends('layouts.super-admin')

@section('title', 'Announcements')
@section('section', 'Announcements')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">PLATFORM COMMUNICATION</span>
            <h1>Announcements</h1>
            <p>Create and publish in-app notices for restaurant owners. This module does not send email.</p>
        </div>
        <a class="button button-primary" href="{{ route('super-admin.announcements.create') }}">Create announcement</a>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ $counts['draft'] }}</strong><span>Drafts</span></div>
        <div class="summary-tile"><strong>{{ $counts['published'] }}</strong><span>Published</span></div>
        <div class="summary-tile"><strong>{{ $counts['archived'] }}</strong><span>Archived</span></div>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.announcements.index') }}" class="toolbar-actions" style="flex:1;" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search announcement title or text">
            <select class="toolbar-search" name="status" aria-label="Filter by status" style="max-width:180px;">
                <option value="">All statuses</option>
                @foreach (['draft', 'published', 'archived'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Support\StatusPresenter::label($status) }}</option>
                @endforeach
            </select>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.announcements.index') }}" data-auto-filter-reset>Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Announcement</th><th>Audience</th><th>Status</th><th>Recipients</th><th>Reads</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    <tr>
                        <td><strong>{{ $announcement->title }}</strong><br><span class="meta-label">{{ Str::limit(strip_tags($announcement->body), 90) }}</span></td>
                        <td>{{ $announcement->audience === 'all' ? 'All active restaurants' : $announcement->restaurants_count.' selected restaurants' }}</td>
                        <td><span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($announcement->status) }}">{{ \App\Support\StatusPresenter::label($announcement->status) }}</span></td>
                        <td>{{ $announcement->audience === 'all' ? 'All' : $announcement->restaurants_count }}</td>
                        <td>{{ $announcement->reads_count }}</td>
                        <td>{{ $announcement->created_at->format('M j, Y') }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.announcements.show', $announcement) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No announcements yet</h3><p>Draft an in-app announcement to communicate with restaurant owners.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($announcements->hasPages())
        <div class="page-tools pagination-tools">{{ $announcements->links() }}</div>
    @endif
@endsection
