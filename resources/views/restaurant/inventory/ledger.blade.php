@extends('layouts.restaurant-admin')

@section('title', 'Stock Ledger')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Immutable movement history</span><h1>Stock ledger</h1><p>Every receipt, issue, transfer and correction is recorded here.</p></div><div class="page-tools"><button class="button button-secondary" type="button" data-print-report>Print ledger</button><a class="button button-secondary" href="{{ route('restaurant.inventory.export', ['report' => 'movement'] + request()->query()) }}">Export CSV</a></div></div>
    <section class="panel-card">
        <form method="GET" class="filter-bar">
            <div class="form-field"><label for="item">Item</label><select id="item" name="item_id"><option value="">All items</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(($filters['item_id'] ?? '') == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="location">Location</label><select id="location" name="location_id"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="type">Movement type</label><select id="type" name="movement_type"><option value="">All types</option>@foreach($movementTypes as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="form-field"><label for="from">From date</label><input id="from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="form-field"><label for="to">To date</label><input id="to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="form-field"><label for="search">Reference</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}"></div>
            <button class="button button-secondary" type="submit">Apply filters</button><a class="button button-secondary" href="{{ route('restaurant.inventory.ledger') }}">Reset</a>
        </form>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Item</th><th>Location</th><th>Movement</th><th>In</th><th>Out</th><th>Cost</th><th>Value</th><th>Balance</th></tr></thead><tbody>
            @forelse($movements as $movement)<tr><td>{{ $movement->occurred_at->format('M j, Y H:i') }}</td><td>{{ $movement->reference ?: $movement->movement_number }}</td><td>{{ $movement->item->name }}</td><td>{{ $movement->location->name }}</td><td>{{ str($movement->movement_type)->replace('_', ' ')->title() }}</td><td>{{ $movement->quantity_in }}</td><td>{{ $movement->quantity_out }}</td><td>₹{{ $movement->unit_cost }}</td><td>₹{{ $movement->value }}</td><td>{{ $movement->balance_after }}</td></tr>
            @empty<tr><td colspan="10"><div class="empty-state"><h3>No stock movements found</h3><p>Adjust your filters or record an opening balance.</p></div></td></tr>@endforelse
        </tbody></table></div>
        @if($movements->hasPages())<div class="page-tools pagination-tools">{{ $movements->links() }}</div>@endif
    </section>
    <link rel="stylesheet" href="{{ asset('assets/restaurant-inventory.css') }}?v={{ filemtime(public_path('assets/restaurant-inventory.css')) }}">
    <script src="{{ asset('assets/restaurant-inventory.js') }}?v={{ filemtime(public_path('assets/restaurant-inventory.js')) }}" defer></script>
@endsection
