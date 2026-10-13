@extends('layouts.super-admin')

@section('title', 'Subscription History')
@section('section', 'Subscription History')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">AUDIT TRAIL</span><h1>Subscription history</h1><p>Recorded assignment, renewal, extension, reactivation, suspension, and cancellation events.</p></div>
    </div>
    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.subscriptions.history') }}" class="toolbar-actions">
            <input class="toolbar-search" type="search" name="search" value="{{ $search }}" placeholder="Search restaurant or event">
            <button class="button button-secondary" type="submit">Search</button>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.subscriptions.history') }}">Reset</a>
    </div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Recorded</th><th>Restaurant</th><th>Event</th><th>Previous</th><th>New</th><th>Actor</th><th></th></tr></thead>
            <tbody>
                @forelse($events as $event)
                    <tr>
                        <td>{{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</td>
                        <td>{{ $event->restaurant?->name ?? 'Restaurant unavailable' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $event->action)) }}</td>
                        <td>{{ $event->previous_status ? ucfirst($event->previous_status) : '—' }}</td>
                        <td>{{ $event->new_status ? ucfirst($event->new_status) : '—' }}</td>
                        <td>{{ $event->actor?->name ?? 'Platform admin account unavailable' }}</td>
                        <td>@if($event->subscription)<a class="button button-secondary" href="{{ route('super-admin.subscriptions.show', $event->subscription) }}">View</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No subscription history</h3><p>Lifecycle events will appear here after subscriptions are assigned.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($events->hasPages())<div class="page-tools pagination-tools">{{ $events->links() }}</div>@endif
@endsection
