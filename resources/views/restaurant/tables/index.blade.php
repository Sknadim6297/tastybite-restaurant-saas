@extends('layouts.restaurant-admin')

@section('title', 'All Tables')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'All Tables', 'description' => 'Configure table locations, capacity and physical availability.'])
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif

    <details class="panel-card table-form-panel" open>
        <summary><strong>Add a table</strong><span>Configure one table or create a numbered batch</span></summary>
        <form method="POST" action="{{ route('restaurant.tables.store') }}" class="table-management-form">@csrf
            <label>Floor<select name="floor_id" required><option value="">Choose floor</option>@foreach($floors as $floor)<option value="{{ $floor->id }}">{{ $floor->name }}</option>@endforeach</select></label>
            <label>Section<select name="section_id"><option value="">No section</option>@foreach($sections as $section)<option value="{{ $section->id }}">{{ $section->floor?->name }} · {{ $section->name }}</option>@endforeach</select></label>
            <label>Table number<input name="table_number" maxlength="32" required placeholder="G01"></label>
            <label>Table name<input name="name" maxlength="100" placeholder="Window table"></label>
            <label>Capacity<input type="number" name="capacity" min="1" max="1000" required value="4"></label>
            <label>Shape<select name="shape" required><option value="round">Round</option><option value="square">Square</option><option value="rectangle">Rectangle</option></select></label>
            <label>Type<select name="type" required>@foreach(['standard' => 'Standard', 'family' => 'Family', 'vip' => 'VIP', 'outdoor' => 'Outdoor', 'banquet' => 'Banquet'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label>Sort order<input type="number" name="sort_order" min="0" value="0"></label>
            <label class="table-form-wide">Description<textarea name="description" rows="2" maxlength="2000"></textarea></label>
            <label class="table-checkbox"><input type="checkbox" name="is_active" value="1" checked> Active</label>
            <button class="button button-primary" type="submit">Create table</button>
        </form>
        <hr>
        <h3>Bulk create tables</h3>
        <form method="POST" action="{{ route('restaurant.tables.bulk') }}" class="table-management-form">@csrf
            <label>Floor<select name="floor_id" required><option value="">Choose floor</option>@foreach($floors as $floor)<option value="{{ $floor->id }}">{{ $floor->name }}</option>@endforeach</select></label>
            <label>Section<select name="section_id"><option value="">No section</option>@foreach($sections as $section)<option value="{{ $section->id }}">{{ $section->floor?->name }} · {{ $section->name }}</option>@endforeach</select></label>
            <label>Prefix<input name="prefix" required maxlength="20" value="G"></label>
            <label>Starting number<input type="number" name="start" min="0" value="1" required></label>
            <label>Number of tables<input type="number" name="count" min="1" max="100" value="10" required></label>
            <label>Capacity<input type="number" name="capacity" min="1" max="1000" value="4" required></label>
            <button class="button button-secondary" type="submit">Create batch</button>
        </form>
    </details>

    <form method="GET" action="{{ route('restaurant.tables.index') }}" class="panel-card table-filter-toolbar" data-table-filter>
        <label>Search<input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Table number or name" data-debounce></label>
        <label>Floor<select name="floor" data-auto-submit><option value="">All floors</option>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected(($filters['floor'] ?? '') == $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
        <label>Section<select name="section" data-auto-submit><option value="">All sections</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected(($filters['section'] ?? '') == $section->id)>{{ $section->name }}</option>@endforeach</select></label>
        <label>Status<select name="status" data-auto-submit><option value="">All statuses</option>@foreach(['available', 'occupied', 'cleaning', 'out_of_service'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Capacity<input type="number" name="capacity" min="1" value="{{ $filters['capacity'] ?? '' }}" data-auto-submit></label>
        <label>Active<select name="active" data-auto-submit><option value="">All</option><option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option><option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option></select></label>
        <span class="table-loading" role="status" hidden>Updating…</span>
        <a class="button button-tertiary" href="{{ route('restaurant.tables.index') }}">Clear filters</a>
    </form>
    <div class="table-list-shell">
        <table class="data-table table-management-list">
            <thead><tr><th>Table</th><th>Floor / Section</th><th>Capacity</th><th>Shape / Type</th><th>Availability</th><th>Current order</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($tables as $table)
                <tr>
                    <td><a href="{{ route('restaurant.tables.show', $table) }}"><strong>{{ $table->table_number }}</strong></a><small>{{ $table->name }}</small></td>
                    <td>{{ $table->floor?->name }}<small>{{ $table->section?->name ?? '—' }}</small></td>
                    <td>{{ $table->capacity }}</td><td>{{ str($table->shape)->title() }} / {{ str($table->type)->title() }}</td>
                    <td><span class="table-status-badge">{{ str($table->status)->replace('_', ' ')->title() }}</span></td>
                    <td>@if($table->activeOccupancy?->order)<a href="{{ route('restaurant.orders.show', $table->activeOccupancy->order) }}">{{ $table->activeOccupancy->order->order_number }}</a>@else—@endif</td>
                    <td><div class="table-row-actions">
                        <details><summary class="button button-secondary">Edit</summary>
                            <form method="POST" action="{{ route('restaurant.tables.update', $table) }}" class="table-inline-edit">@csrf @method('PUT')
                                <label>Number<input name="table_number" value="{{ $table->table_number }}" required></label>
                                <label>Name<input name="name" value="{{ $table->name }}"></label>
                                <label>Floor<select name="floor_id" required>@foreach($floors as $floor)<option value="{{ $floor->id }}" @selected($table->floor_id === $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
                                <label>Section<select name="section_id"><option value="">No section</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected($table->section_id === $section->id)>{{ $section->name }}</option>@endforeach</select></label>
                                <label>Capacity<input type="number" name="capacity" min="1" value="{{ $table->capacity }}" required></label>
                                <label>Shape<select name="shape">@foreach(['round', 'square', 'rectangle'] as $shape)<option @selected($table->shape === $shape) value="{{ $shape }}">{{ str($shape)->title() }}</option>@endforeach</select></label>
                                <label>Type<select name="type">@foreach(['standard', 'family', 'vip', 'outdoor', 'banquet'] as $type)<option @selected($table->type === $type) value="{{ $type }}">{{ str($type)->title() }}</option>@endforeach</select></label>
                                <label class="table-checkbox"><input type="checkbox" name="is_active" value="1" @checked($table->is_active)> Active</label>
                                <button class="button button-primary" type="submit">Save changes</button>
                            </form>
                        </details>
                        <a class="button button-tertiary" href="{{ route('restaurant.tables.show', $table) }}">Details</a>
                        <form method="POST" action="{{ route('restaurant.tables.archive', $table) }}" onsubmit="return confirm('Archive this table? Historical records will remain.');">@csrf @method('DELETE')<button class="button button-danger" type="submit" @disabled($table->status === 'occupied')>Archive</button></form>
                    </div></td>
                </tr>
            @empty<tr><td colspan="7"><div class="empty-state"><h2>No matching tables</h2><p>Change filters or add your first table above.</p></div></td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div class="table-pagination">{{ $tables->links() }}</div>
    <script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
@endsection
