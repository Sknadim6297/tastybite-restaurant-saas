@extends('layouts.super-admin')

@section('title', 'Support Tickets')
@section('section', 'Support Tickets')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">RESTAURANT SUPPORT</span><h1>Support tickets</h1><p>Review restaurant requests, manage assignment, and keep every status change in history.</p></div>
    </div>

    <section class="subscription-overview ticket-counts" aria-label="Ticket status counts">
        @foreach(['open' => 'Open', 'in_progress' => 'In progress', 'waiting_for_restaurant' => 'Waiting for restaurant', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $status => $label)
            <div><span class="meta-label">{{ strtoupper($label) }}</span><strong>{{ $counts[$status] ?? 0 }}</strong></div>
        @endforeach
    </section>

    <div class="page-tools ticket-filter-tools">
        <form method="GET" action="{{ route('super-admin.support-tickets.index') }}" class="toolbar-actions">
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Ticket, subject, or restaurant">
            <select class="toolbar-search" name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach(['open', 'in_progress', 'waiting_for_restaurant', 'resolved', 'closed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
            <select class="toolbar-search" name="priority" aria-label="Filter by priority">
                <option value="">All priorities</option>
                @foreach(['low', 'normal', 'high', 'urgent'] as $priority)
                    <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ ucfirst($priority) }}</option>
                @endforeach
            </select>
            <select class="toolbar-search" name="restaurant_id" aria-label="Filter by restaurant">
                <option value="">All restaurants</option>
                @foreach($restaurants as $restaurant)
                    <option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>
                @endforeach
            </select>
            <select class="toolbar-search" name="assigned_admin_id" aria-label="Filter by assigned administrator">
                <option value="">All administrators</option>
                @foreach($admins as $admin)
                    <option value="{{ $admin->id }}" @selected((string) ($filters['assigned_admin_id'] ?? '') === (string) $admin->id)>{{ $admin->name }}</option>
                @endforeach
            </select>
            <button class="button button-secondary" type="submit">Apply filters</button>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.support-tickets.index') }}">Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Ticket</th><th>Restaurant / Owner</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned Admin</th><th>Updated</th><th></th></tr></thead>
            <tbody>
                @forelse($tickets as $ticket)
                    <tr>
                        <td><strong>{{ $ticket->ticket_number }}</strong><br><span class="meta-label">{{ $ticket->subject }}</span></td>
                        <td>{{ $ticket->restaurant->name }}<br><span class="meta-label">{{ $ticket->creator->name }}</span></td>
                        <td>{{ ucfirst(str_replace('_', ' ', $ticket->category)) }}</td>
                        <td><span class="status-pill {{ $ticket->priority === 'urgent' ? 'status-rejected' : 'status-pending' }}">{{ ucfirst($ticket->priority) }}</span></td>
                        <td><span class="status-pill status-{{ $ticket->status === 'in_progress' ? 'active' : ($ticket->status === 'waiting_for_restaurant' ? 'pending' : $ticket->status) }}">{{ ucfirst(str_replace('_', ' ', $ticket->status)) }}</span></td>
                        <td>{{ $ticket->assignedAdmin?->name ?? 'Unassigned' }}</td>
                        <td>{{ $ticket->last_activity_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.support-tickets.show', $ticket) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><h3>No support tickets found</h3><p>New restaurant requests will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($tickets->hasPages())<div class="page-tools pagination-tools">{{ $tickets->links() }}</div>@endif
@endsection
