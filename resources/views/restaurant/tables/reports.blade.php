@extends('layouts.restaurant-admin')

@section('title', 'Table Reports')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Table Reports', 'description' => 'Occupancy and reservation analytics from recorded table sessions; order sales and collected payments are distinct.'])
    <form method="GET" action="{{ route('restaurant.tables.reports') }}" class="panel-card table-filter-toolbar" data-table-filter>
        <label>Date range<select name="period" data-auto-submit><option value="today" @selected(request('period', 'today') === 'today')>Today</option><option value="yesterday" @selected(request('period') === 'yesterday')>Yesterday</option><option value="7" @selected(request('period') === '7')>Last 7 days</option><option value="30" @selected(request('period') === '30')>Last 30 days</option><option value="custom" @selected(request('period') === 'custom')>Custom</option></select></label>
        <label>From<input type="date" name="from" value="{{ request('from', $start) }}" data-auto-submit></label>
        <label>To<input type="date" name="to" value="{{ request('to', $end) }}" data-auto-submit></label>
        <label>Floor<select name="floor" data-auto-submit><option value="">All floors</option>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected(request('floor') == $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
        <label>Section<select name="section" data-auto-submit><option value="">All sections</option>@foreach($tables->pluck('section')->filter()->unique('id') as $section)<option value="{{ $section->id }}" @selected(request('section') == $section->id)>{{ $section->name }}</option>@endforeach</select></label>
        <label>Table<select name="table" data-auto-submit><option value="">All tables</option>@foreach($tables as $table)<option value="{{ $table->id }}" @selected(request('table') == $table->id)>{{ $table->table_number }}</option>@endforeach</select></label>
        <span class="table-loading" role="status" hidden>Updating…</span><a class="button button-tertiary" href="{{ route('restaurant.tables.reports') }}">Clear filters</a>
        <a class="button button-secondary" href="{{ route('restaurant.tables.reports.export', request()->query()) }}">Export CSV</a>
    </form>
    <section class="table-kpi-grid table-report-kpis">
        @foreach([
            ['Tables in scope', $tableCount],
            ['Total floors', $floorCount],
            ['Total sections', $sectionCount],
            ['Currently occupied', $occupiedCount],
            ['Current occupancy rate', $tableCount ? number_format($occupiedCount / $tableCount * 100, 1).'%' : '0%'],
            ['Average recorded session', $averageMinutes === null ? '—' : $averageMinutes.' min'],
            ['Recorded table turnover', $turnover],
            ['Average cleaning duration', $averageCleaningMinutes === null ? '—' : $averageCleaningMinutes.' min'],
            ['Reservations in range', $reservationCount],
            ['Reservation cancellation rate', $cancelledRate.'%'],
            ['No-shows', $noShowCount],
            ['No-show rate', $noShowRate.'%'],
            ['Completed dine-in order value', '₹'.number_format((float) $sales, 2)],
            ['Recorded payments', '₹'.number_format((float) $recordedPayments, 2)],
            ['Recorded refunds', '₹'.number_format((float) $refundTotal, 2)],
            ['Net confirmed collected', '₹'.number_format((float) $collected, 2)],
        ] as [$label, $value])
            <article class="table-kpi-card"><small>{{ $label }}</small><strong>{{ $value }}</strong></article>
        @endforeach
    </section>
    <section class="panel-card">
        <div class="table-section-title"><div><h2>Section utilization</h2><p>Current occupied table counts and completed dine-in sales for the selected period.</p></div></div>
        <div class="table-list-shell"><table class="data-table"><thead><tr><th>Section</th><th>Total tables</th><th>Occupied now</th><th>Utilization</th><th>Order value</th></tr></thead><tbody>
            @forelse($sections as $section)
                <tr><td>{{ $section->name }}<small>{{ $section->floor?->name }}</small></td><td>{{ $section->total_tables }}</td><td>{{ $section->occupied_tables }}</td><td>{{ $section->total_tables ? number_format($section->occupied_tables / $section->total_tables * 100, 1) : 0 }}%</td><td>₹{{ number_format((float) ($sectionSales[$section->id] ?? 0), 2) }}</td></tr>
            @empty<tr><td colspan="5">No sections match these filters.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel-card">
        <div class="table-section-title"><div><h2>Floor utilization</h2><p>Current physical occupancy; historical sessions are listed below.</p></div></div>
        <div class="table-list-shell"><table class="data-table"><thead><tr><th>Floor</th><th>Total tables</th><th>Occupied now</th><th>Utilization</th><th>Floor sales</th></tr></thead><tbody>
            @forelse($floors as $floor)
                <tr><td>{{ $floor->name }}</td><td>{{ $floor->total_tables }}</td><td>{{ $floor->occupied_tables }}</td><td>{{ $floor->total_tables ? number_format($floor->occupied_tables / $floor->total_tables * 100, 1) : 0 }}%</td><td>₹{{ number_format((float) ($floorSales[$floor->id] ?? 0), 2) }}</td></tr>
            @empty<tr><td colspan="5">No floor data.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel-card">
        <div class="table-section-title"><div><h2>Table usage and sales</h2><p>Only completed orders and occupancy sessions actually recorded in this date range are included.</p></div></div>
        <div class="table-list-shell"><table class="data-table"><thead><tr><th>Table</th><th>Floor / Section</th><th>Current status</th><th>Completed sessions</th><th>Order value</th></tr></thead><tbody>
            @forelse($tables as $table)
                <tr><td>{{ $table->table_number }}</td><td>{{ $table->floor?->name }}<small>{{ $table->section?->name }}</small></td><td>{{ str($table->status)->replace('_', ' ')->title() }}</td><td>{{ $table->occupancies->count() }}</td><td>₹{{ number_format((float) ($tableSales[$table->id]->total_sales ?? 0), 2) }}</td></tr>
            @empty<tr><td colspan="5">No table data in this scope.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <section class="panel-card">
        <h2>Peak recorded dining hours</h2>
        @forelse($peakHours as $hour => $sessions)
            <div class="sales-trend-row"><span>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00</span><div><i style="width: {{ max(3, $sessionCount ? $sessions / $sessionCount * 100 : 3) }}%"></i></div><strong>{{ $sessions }} sessions</strong></div>
        @empty<p class="muted-copy">No completed occupancy sessions are recorded for this period.</p>@endforelse
    </section>
    <div class="table-management-columns">
        @foreach([['Most used tables', $mostUsedTables], ['Least used tables', $leastUsedTables]] as [$usageTitle, $usageTables])
            <section class="panel-card"><h2>{{ $usageTitle }}</h2>
                @forelse($usageTables as $table)
                    <p class="table-report-usage-row"><strong>{{ $table->table_number }}</strong><span>{{ $table->occupancies->count() }} sessions · ₹{{ number_format((float) ($tableSales[$table->id]->total_sales ?? 0), 2) }}</span></p>
                @empty<p class="muted-copy">No tables match these filters.</p>@endforelse
            </section>
        @endforeach
    </div>
    <script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
@endsection
