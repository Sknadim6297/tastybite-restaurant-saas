@extends('layouts.restaurant-admin')

@section('title', 'Table Operations')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Table Operations', 'description' => 'Transfer active parties, group physical tables and work through the cleaning queue.'])
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice-card notice-card-error" role="alert">{{ $errors->first() }}</div>@endif

    <div class="table-management-columns">
        <section class="panel-card">
            <h2>Transfer an active table</h2>
            <p>Orders, payment records and inventory history remain unchanged. The source moves to cleaning.</p>
            <form method="POST" action="{{ route('restaurant.tables.transfer') }}" class="table-management-form">@csrf
                <label>Source table<select name="source_table_id" required><option value="">Choose occupied table</option>@foreach($tables->where('status', 'occupied') as $table)<option value="{{ $table->id }}">{{ $table->table_number }} · {{ $table->floor?->name }}</option>@endforeach</select></label>
                <label>Destination table<select name="destination_table_id" required><option value="">Choose available table</option>@foreach($tables->where('status', 'available')->where('is_active', true) as $table)<option value="{{ $table->id }}">{{ $table->table_number }} · {{ $table->floor?->name }} · seats {{ $table->capacity }}</option>@endforeach</select></label>
                <label class="table-form-wide">Reason<input name="reason" maxlength="1000" required placeholder="Guest requested a quieter area"></label>
                <button class="button button-primary" type="submit">Transfer party</button>
            </form>
        </section>
        <section class="panel-card">
            <h2>Physical table merge</h2><p>Group tables for one party without merging separate orders or bills.</p>
            <form method="POST" action="{{ route('restaurant.tables.merge') }}" class="table-merge-form">@csrf
                <div class="table-merge-options">@foreach($tables->where('is_active', true)->where('status', 'available') as $table)
                    <label><input type="checkbox" name="table_ids[]" value="{{ $table->id }}"> {{ $table->table_number }} <small>{{ $table->floor?->name }} · {{ $table->capacity }} seats</small></label>
                @endforeach</div>
                <label>Party size<input type="number" name="guest_count" min="1" max="1000" value="2" required></label>
                <button class="button button-secondary" type="submit">Group selected tables</button>
            </form>
        </section>
    </div>

    @if($groups->isNotEmpty())
        <section class="panel-card"><h2>Active physical groups</h2>
            @foreach($groups as $group)
                <div class="table-operation-row"><div><strong>Group #{{ $group->id }}</strong><p>{{ $group->members->map(fn($member) => $member->table?->table_number)->filter()->join(' + ') }} · {{ $group->guest_count }} guests</p></div>
                    <form method="POST" action="{{ route('restaurant.tables.split') }}" onsubmit="return confirm('Split this physical group?');">@csrf<input type="hidden" name="group_id" value="{{ $group->id }}"><button class="button button-secondary">Split group</button></form>
                </div>
            @endforeach
        </section>
    @endif

    <section class="panel-card">
        <div class="table-section-title"><div><h2>Active table sessions</h2><p>Occupancy is only started by a persisted POS dine-in order.</p></div><span>{{ $tables->whereNotNull('activeOccupancy')->count() }} active</span></div>
        <div class="table-list-shell"><table class="data-table table-management-list"><thead><tr><th>Table</th><th>Floor / Section</th><th>Order</th><th>Guests</th><th>Occupied since</th><th>Duration</th></tr></thead><tbody>
            @forelse($tables->filter(fn($table) => $table->activeOccupancy) as $table)
                <tr><td><a href="{{ route('restaurant.tables.show', $table) }}">{{ $table->table_number }}</a></td><td>{{ $table->floor?->name }}<small>{{ $table->section?->name }}</small></td><td>@if($table->activeOccupancy->order)<a href="{{ route('restaurant.orders.show', $table->activeOccupancy->order) }}">{{ $table->activeOccupancy->order->order_number }}</a>@elseif($table->activeOccupancy->group_id)Group #{{ $table->activeOccupancy->group_id }}@else—@endif</td><td>{{ $table->activeOccupancy->group?->guest_count ?? $table->activeOccupancy->guest_count }}</td><td>{{ $table->activeOccupancy->started_at->timezone(config('app.timezone'))->format('M j · H:i') }}</td><td>{{ $table->activeOccupancy->started_at->diffForHumans(now(), true) }}</td></tr>
            @empty<tr><td colspan="6"><p class="muted-copy">No active table sessions.</p></td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="panel-card">
        <div class="table-section-title"><div><h2>Cleaning queue</h2><p>Complete cleaning to return a table to service.</p></div><span>{{ $tables->where('status', 'cleaning')->count() }} waiting</span></div>
        <div class="table-card-grid table-cleaning-grid">
            @forelse($tables->where('status', 'cleaning') as $table)
                <article class="table-status-card status-cleaning"><div class="table-card-top"><strong>{{ $table->table_number }}</strong><span class="table-status-badge">Cleaning</span></div><p>{{ $table->floor?->name }} · {{ $table->section?->name }}</p>
                    <form method="POST" action="{{ route('restaurant.tables.status', $table) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="available"><button class="button button-primary">Mark clean &amp; available</button></form>
                </article>
            @empty<p class="muted-copy">No tables waiting for cleaning.</p>@endforelse
        </div>
    </section>

    <section class="panel-card"><h2>Recent transfers</h2>
        <div class="table-list-shell"><table class="data-table"><thead><tr><th>From</th><th>To</th><th>Order</th><th>Reason</th><th>Time</th></tr></thead><tbody>
            @forelse($transfers as $transfer)<tr><td>{{ $transfer->source?->table_number }}</td><td>{{ $transfer->destination?->table_number }}</td><td>{{ $transfer->pos_order_id ? '#'.$transfer->pos_order_id : '—' }}</td><td>{{ $transfer->reason }}</td><td>{{ $transfer->transferred_at?->timezone(config('app.timezone'))->format('M j · H:i') }}</td></tr>@empty<tr><td colspan="5">No transfers recorded.</td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection
