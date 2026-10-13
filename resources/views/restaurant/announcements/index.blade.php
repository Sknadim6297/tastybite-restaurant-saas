@extends('layouts.restaurant-admin')

@section('title', 'Announcements')
@section('section', 'Announcements')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PLATFORM UPDATES</span><h1>Announcements</h1><p>Messages from the TastyBite platform team for {{ $restaurant->name }}.</p></div>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Announcement</th><th>Published</th><th>Expiry</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    @php($isRead = $announcement->reads->isNotEmpty())
                    <tr>
                        <td><strong>{{ $announcement->title }}</strong><br><span class="meta-label">{{ Str::limit(strip_tags($announcement->body), 100) }}</span></td>
                        <td>{{ $announcement->published_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td>
                        <td>{{ $announcement->expires_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</td>
                        <td><span class="status-pill status-{{ $isRead ? 'disabled' : 'pending' }}">{{ $isRead ? 'Read' : 'New' }}</span></td>
                        <td><a class="button button-secondary" href="{{ route('restaurant.announcements.show', $announcement) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><h3>No announcements</h3><p>There are no current platform notices for your restaurant.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($announcements->hasPages())
        <div class="page-tools pagination-tools">{{ $announcements->links() }}</div>
    @endif
@endsection
