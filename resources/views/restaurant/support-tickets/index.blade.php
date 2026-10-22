@extends('layouts.restaurant-admin')

@section('title', 'Support Tickets')
@section('section', 'Support Tickets')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">RESTAURANT SUPPORT</span><h1>Support tickets</h1><p>Contact the TastyBite team and follow updates for your restaurant.</p></div>
        <a class="button button-primary" href="{{ route('restaurant.support.create') }}">Create ticket</a>
    </div>

    @if($notifications->isNotEmpty())
        <section class="panel-card ticket-notifications" aria-labelledby="support-notifications-heading">
            <div class="section-heading"><div><h2 id="support-notifications-heading">Ticket notifications <span class="notification-count-inline">{{ $unreadCount }} unread</span></h2><p>Private updates for your owner account.</p></div></div>
            @foreach($notifications as $notification)
                <div class="ticket-notification {{ $notification->read_at ? '' : 'ticket-notification-unread' }}">
                    <div><strong>{{ $notification->title }}</strong><p>{{ $notification->description }}</p><span class="meta-label">{{ $notification->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span></div>
                    <form method="POST" action="{{ route('restaurant.support.notifications.read', $notification) }}">@csrf<button class="button button-secondary" type="submit">Open ticket</button></form>
                </div>
            @endforeach
        </section>
    @endif

    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Ticket</th><th>Category</th><th>Status</th><th>Priority</th><th>Assigned Admin</th><th>Last update</th><th></th></tr></thead>
            <tbody>
                @forelse($tickets as $ticket)
                    <tr><td><strong>{{ $ticket->ticket_number }}</strong><br><span class="meta-label">{{ $ticket->subject }}</span></td><td>{{ \App\Support\StatusPresenter::label($ticket->category) }}</td><td><span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($ticket->status) }}">{{ \App\Support\StatusPresenter::label($ticket->status) }}</span></td><td>{{ ucfirst($ticket->priority) }}</td><td>{{ $ticket->assignedAdmin?->name ?? 'Unassigned' }}</td><td>{{ $ticket->last_activity_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td><td><a class="button button-secondary" href="{{ route('restaurant.support.show', $ticket) }}">View</a></td></tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No tickets yet</h3><p>Create a support ticket when you need help from TastyBite.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="page-tools pagination-tools">{{ $tickets->links() }}</div>
@endsection
