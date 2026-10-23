@extends('layouts.restaurant-admin')

@section('title', 'Table Dashboard')
@section('section', 'Table Management')

@section('content')
    <div class="page-heading table-page-heading">
        <div><span class="eyebrow">TABLE MANAGEMENT</span><h1>Table Dashboard</h1><p>Monitor restaurant tables, occupancy, and daily operations.</p></div>
        <div class="table-dashboard-actions">
            <a class="button button-primary" href="{{ route('restaurant.tables.manage', ['tab' => 'tables']) }}">Add Table</a>
            <a class="button button-secondary" href="{{ route('restaurant.tables.reservations') }}">New Reservation</a>
            <a class="button button-tertiary" href="{{ route('restaurant.tables.manage', ['tab' => 'tables']) }}">View All Tables</a>
        </div>
    </div>
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif

    <section class="table-kpi-grid" aria-label="Table metrics">
        @foreach([
            ['Total tables', $metrics['total'], 'bi-grid'],
            ['Available', $metrics['available'], 'bi-check-circle'],
            ['Occupied', $metrics['occupied'], 'bi-people'],
            ['Cleaning', $metrics['cleaning'], 'bi-stars'],
            ['Out of service', $metrics['out_of_service'], 'bi-slash-circle'],
            ['Current guests', $metrics['guests'], 'bi-person'],
            ['Active dine-in orders', $metrics['orders'], 'bi-receipt'],
            ["Today's reservations", $metrics['reservations'], 'bi-calendar-check'],
        ] as [$label, $value, $icon])
            <article class="table-kpi-card"><span><i class="bi {{ $icon }}" aria-hidden="true"></i></span><small>{{ $label }}</small><strong>{{ number_format((int) $value) }}</strong></article>
        @endforeach
    </section>

    <nav class="table-workspace-tabs" role="tablist" aria-label="Table dashboard views">
        @foreach(['live' => 'Live Tables', 'operations' => 'Table Operations', 'cleaning' => 'Cleaning Queue', 'sessions' => 'Active Sessions'] as $value => $label)
            <a role="tab" aria-selected="{{ $tab === $value ? 'true' : 'false' }}" @class(['is-active' => $tab === $value]) href="{{ route('restaurant.tables.overview', ['tab' => $value] + ($value === 'live' && request()->filled('floor') ? ['floor' => request('floor')] : [])) }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'live')
        <form class="panel-card table-filter-toolbar" method="GET" action="{{ route('restaurant.tables.overview') }}" data-table-filter>
            <input type="hidden" name="tab" value="live">
            <label for="table-floor-filter">Floor<select id="table-floor-filter" name="floor" data-auto-submit><option value="">All floors</option>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected(request('floor') == $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
            <span class="table-loading" role="status" aria-live="polite" hidden>Updating…</span><small>Status refreshes automatically every 30 seconds.</small>
            <a class="button button-tertiary" href="{{ route('restaurant.tables.manage', ['tab' => 'floors']) }}">Manage Floors</a>
        </form>
        @if($tables->isEmpty())
            <div class="panel-card empty-state"><h2>No tables configured</h2><p>Add floors and tables to start monitoring the dining room.</p><a class="button button-primary" href="{{ route('restaurant.tables.manage', ['tab' => 'floors']) }}">Add Floor</a></div>
        @else
            <div id="table-overview-live" data-feed-url="{{ route('restaurant.tables.status-feed', request()->only('floor')) }}">
            @foreach($tables->groupBy(fn($table) => $table->floor?->name ?? 'Unassigned') as $floorName => $floorTables)
                <section class="table-floor-section"><div class="table-section-title"><h2>{{ $floorName }}</h2><span>{{ $floorTables->count() }} tables</span></div><div class="table-card-grid">
                    @foreach($floorTables as $table)
                        @php($occupancy = $table->activeOccupancy)
                        <article class="table-status-card status-{{ $table->status }}" data-table-card="{{ $table->id }}" data-status="{{ $table->status }}">
                            <div class="table-card-top"><strong>{{ $table->table_number }}</strong><span class="table-status-badge">{{ str($table->status)->replace('_', ' ')->title() }}</span></div>
                            <p>{{ $table->floor?->name }} · {{ $table->section?->name ?? 'No section' }} · Seats {{ $table->capacity }}</p>
                            @if($occupancy)<div class="table-session-summary"><strong>{{ $occupancy->group?->guest_count ?? $occupancy->guest_count }} guests{{ $occupancy->group_id ? ' in combined party' : '' }}</strong><span>Since {{ $occupancy->started_at?->timezone(config('app.timezone'))->format('H:i') }} · {{ $occupancy->started_at?->diffForHumans(now(), true) }}</span>@if($occupancy->order)<a href="{{ route('restaurant.orders.show', $occupancy->order) }}">{{ $occupancy->order->order_number }}</a>@endif</div>@endif
                            @if($table->upcoming_reservations_count)<span class="table-reservation-flag"><i class="bi bi-calendar-event" aria-hidden="true"></i> Upcoming reservation</span>@endif
                            <div class="table-card-actions">
                                <a class="button button-secondary" href="{{ route('restaurant.tables.show', $table) }}">Details</a>
                                @if($table->status === 'occupied' && $occupancy?->order)<a class="button button-secondary" href="{{ route('restaurant.orders.show', $occupancy->order) }}">Active order</a>@else<a class="button button-primary" href="{{ route('pos.index') }}">Open POS</a>@endif
                                @if($table->status === 'occupied')<a class="button button-tertiary" href="{{ route('restaurant.tables.overview', ['tab' => 'operations', 'source' => $table->id]) }}">Transfer</a>@endif
                                <a class="button button-tertiary" href="{{ route('restaurant.tables.reservations', ['table' => $table->id]) }}">Reservations</a>
                                @if(in_array($table->status, ['available', 'cleaning', 'out_of_service'], true))<form method="POST" action="{{ route('restaurant.tables.status', $table) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $table->status === 'available' ? 'cleaning' : 'available' }}"><button class="button button-tertiary">{{ $table->status === 'available' ? 'Mark cleaning' : 'Mark available' }}</button></form>@endif
                            </div>
                        </article>
                    @endforeach
                </div></section>
            @endforeach
            </div>
        @endif
    @elseif($tab === 'operations')
        @include('restaurant.tables.partials.dashboard-operations')
    @elseif($tab === 'cleaning')
        @include('restaurant.tables.partials.dashboard-cleaning')
    @else
        @include('restaurant.tables.partials.dashboard-sessions')
    @endif
    <script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
@endsection
