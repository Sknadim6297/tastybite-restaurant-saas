@extends('layouts.restaurant-admin')

@section('title', 'Table Reservations')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Table Reservations', 'description' => 'Manage guest bookings in restaurant-local time and prevent overlapping table assignments.'])
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    <details class="panel-card table-form-panel" open>
        <summary><strong>Create a reservation</strong><span>Assign a table now or leave unassigned</span></summary>
        <form method="POST" action="{{ route('restaurant.tables.reservations.store') }}" class="table-management-form">@csrf
            <label>Customer name<input name="customer_name" maxlength="160" required></label>
            <label>Phone<input name="phone" maxlength="32" required></label>
            <label>Email (optional)<input type="email" name="email" maxlength="190"></label>
            <label>Date &amp; time<input type="datetime-local" name="starts_at" required></label>
            <label>Expected duration (minutes)<input type="number" name="duration_minutes" min="15" max="1440" value="120" required></label>
            <label>Party size<input type="number" name="party_size" min="1" max="1000" value="2" required></label>
            <label>Preferred floor<select name="floor_id"><option value="">Any floor</option>@foreach($floors as $floor)<option value="{{ $floor->id }}">{{ $floor->name }}</option>@endforeach</select></label>
            <label>Table<select name="table_id"><option value="">Unassigned</option>@foreach($tables as $table)<option value="{{ $table->id }}">{{ $table->table_number }} · {{ $table->floor?->name }} · seats {{ $table->capacity }}</option>@endforeach</select></label>
            <label class="table-form-wide">Special requests<textarea name="special_requests" rows="2" maxlength="2000"></textarea></label>
            <label class="table-form-wide">Internal notes<textarea name="internal_notes" rows="2" maxlength="2000"></textarea></label>
            <button class="button button-primary" type="submit">Save reservation</button>
        </form>
    </details>

    <form method="GET" action="{{ route('restaurant.tables.reservations') }}" class="panel-card table-filter-toolbar" data-table-filter>
        <label>Search<input type="search" name="search" value="{{ request('search') }}" placeholder="Name, phone or reference" data-debounce></label>
        <label>Calendar view<select name="period" data-auto-submit><option value="day" @selected($calendarPeriod === 'day')>Day</option><option value="week" @selected($calendarPeriod === 'week')>Week</option><option value="month" @selected($calendarPeriod === 'month')>Month</option></select></label>
        <label>Calendar date<input type="date" name="date" value="{{ $calendarDate }}" data-auto-submit></label>
        <label>Status<select name="status" data-auto-submit><option value="">All statuses</option>@foreach(['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Floor<select name="floor" data-auto-submit><option value="">All floors</option>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected(request('floor') == $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
        <label>Table<select name="table" data-auto-submit><option value="">All tables</option>@foreach($tables as $table)<option value="{{ $table->id }}" @selected(request('table') == $table->id)>{{ $table->table_number }}</option>@endforeach</select></label>
        <span class="table-loading" role="status" hidden>Updating…</span><a class="button button-tertiary" href="{{ route('restaurant.tables.reservations') }}">Clear</a>
    </form>

    <div class="table-list-shell"><table class="data-table table-management-list">
        <thead><tr><th>Reference / Guest</th><th>Reservation</th><th>Party</th><th>Floor / Table</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>@forelse($reservations as $reservation)
            <tr>
                <td><strong>{{ $reservation->reference }}</strong><small>{{ $reservation->customer_name }} · {{ $reservation->phone }}</small></td>
                <td>{{ $reservation->starts_at?->timezone(config('app.timezone'))->format('M j, Y · H:i') }}<small>{{ $reservation->duration_minutes }} minutes</small></td>
                <td>{{ $reservation->party_size }}</td><td>{{ $reservation->floor?->name ?? 'Any floor' }}<small>{{ $reservation->table?->table_number ?? 'Unassigned' }}</small></td>
                <td><span class="table-status-badge">{{ str($reservation->status)->replace('_', ' ')->title() }}</span></td>
                <td><div class="table-row-actions">
                    @if(! in_array($reservation->status, ['completed', 'cancelled', 'no_show'], true))
                        <details><summary class="button button-secondary">Edit / reschedule</summary>
                            <form method="POST" action="{{ route('restaurant.tables.reservations.update', $reservation) }}" class="table-inline-edit">@csrf @method('PUT')
                                <label>Name<input name="customer_name" value="{{ $reservation->customer_name }}" required></label>
                                <label>Phone<input name="phone" value="{{ $reservation->phone }}" required></label>
                                <label>Email<input type="email" name="email" value="{{ $reservation->email }}"></label>
                                <label>Date / time<input type="datetime-local" name="starts_at" value="{{ $reservation->starts_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') }}" required></label>
                                <label>Duration (min)<input type="number" name="duration_minutes" min="15" max="1440" value="{{ $reservation->duration_minutes }}" required></label>
                                <label>Party size<input type="number" name="party_size" min="1" value="{{ $reservation->party_size }}" required></label>
                                <label>Floor<select name="floor_id"><option value="">Any floor</option>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected($reservation->floor_id === $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
                                <label>Table<select name="table_id"><option value="">Unassigned</option>@foreach($tables as $table)<option value="{{ $table->id }}" @selected($reservation->table_id === $table->id)>{{ $table->table_number }} · {{ $table->floor?->name }}</option>@endforeach</select></label>
                                <label class="table-form-wide">Special requests<textarea name="special_requests">{{ $reservation->special_requests }}</textarea></label>
                                <label class="table-form-wide">Internal notes<textarea name="internal_notes">{{ $reservation->internal_notes }}</textarea></label>
                                <button class="button button-primary">Save changes</button>
                            </form>
                        </details>
                    @endif
                    @foreach(match($reservation->status) { 'pending' => ['confirmed' => 'Confirm', 'cancelled' => 'Cancel', 'no_show' => 'No show'], 'confirmed' => ['seated' => 'Seat guest', 'cancelled' => 'Cancel', 'no_show' => 'No show'], 'seated' => ['completed' => 'Complete'], default => [] } as $next => $label)
                        <form method="POST" action="{{ route('restaurant.tables.reservations.status', $reservation) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $next }}"><button class="button button-secondary" type="submit">{{ $label }}</button></form>
                    @endforeach
                </div></td>
            </tr>
        @empty<tr><td colspan="6"><div class="empty-state"><h2>No reservations found</h2><p>Bookings will appear here as they are created.</p></div></td></tr>@endforelse</tbody>
    </table></div>
    <div class="table-pagination">{{ $reservations->links() }}</div>
    <script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
@endsection
