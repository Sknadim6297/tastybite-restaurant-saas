@extends('layouts.restaurant-admin')

@section('title', $item->name)
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">{{ $item->item_code }}</span><h1>{{ $item->name }}</h1><p>{{ $item->description ?: 'Inventory item details and stock history.' }}</p></div>
        <div class="page-tools">
            <a class="button button-secondary" href="{{ route('restaurant.inventory.items.index') }}">Back to items</a>
            <a class="button button-primary" href="{{ route('restaurant.inventory.items.edit', $item) }}">Edit item</a>
        </div>
    </div>

    <div class="stats-grid">
        <article class="stat-card"><span>Current stock</span><strong>{{ \Brick\Math\BigDecimal::of($item->current_quantity)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $item->baseUnit->abbreviation }}</strong></article>
        <article class="stat-card"><span>Average unit cost</span><strong>₹{{ \Brick\Math\BigDecimal::of($item->average_unit_cost)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</strong></article>
        <article class="stat-card"><span>Stock value</span><strong>₹{{ $item->stock_value }}</strong></article>
        <article class="stat-card"><span>Reorder level</span><strong>{{ $item->reorder_level }} {{ $item->baseUnit->abbreviation }}</strong></article>
    </div>

    <div class="content-grid">
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Item details</h2><p>Catalog and stock-tracking configuration.</p></div><span class="status-badge {{ $item->status === 'active' ? 'status-badge-success' : 'status-badge-muted' }}">{{ ucfirst($item->status) }}</span></div>
            <div class="details-grid">
                <div><span>Category</span><strong>{{ $item->category->name }}</strong></div>
                <div><span>SKU</span><strong>{{ $item->sku ?: '—' }}</strong></div>
                <div><span>Base unit</span><strong>{{ $item->baseUnit->name }} ({{ $item->baseUnit->abbreviation }})</strong></div>
                <div><span>Purchase unit</span><strong>{{ $item->purchaseUnit?->name ?: '—' }}</strong></div>
                <div><span>Consumption unit</span><strong>{{ $item->consumptionUnit?->name ?: '—' }}</strong></div>
                <div><span>Reorder quantity</span><strong>{{ $item->reorder_quantity }} {{ $item->baseUnit->abbreviation }}</strong></div>
                <div><span>Standard cost</span><strong>₹{{ $item->standard_cost }}</strong></div>
                <div><span>Tax rate</span><strong>{{ $item->tax_rate }}%</strong></div>
                <div><span>Batch tracking</span><strong>{{ $item->track_batches ? 'Enabled' : 'Disabled' }}</strong></div>
                <div><span>Expiry tracking</span><strong>{{ $item->track_expiry ? 'Enabled' : 'Disabled' }}</strong></div>
                @if($item->notes)<div class="detail-wide"><span>Notes</span><strong>{{ $item->notes }}</strong></div>@endif
            </div>
        </section>
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Location balances</h2><p>Available quantity and weighted average cost by location.</p></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Location</th><th>Quantity</th><th>Average cost</th><th>Value</th></tr></thead><tbody>
                @forelse($item->balances as $balance)
                    <tr><td>{{ $balance->location->name }}</td><td>{{ $balance->quantity }} {{ $item->baseUnit->abbreviation }}</td><td>₹{{ $balance->average_unit_cost }}</td><td>₹{{ \Brick\Math\BigDecimal::of($balance->quantity)->multipliedBy($balance->average_unit_cost)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</td></tr>
                @empty<tr><td colspan="4"><div class="empty-state"><h3>No stock balances</h3><p>Record opening stock or a receipt to create a balance.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    @if($item->track_batches || $item->track_expiry)
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Tracked batches</h2><p>Lot-level quantity and expiry details.</p></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Batch</th><th>Location</th><th>Received</th><th>Available</th><th>Manufactured</th><th>Expires</th></tr></thead><tbody>
                @forelse($batches as $batch)<tr><td>{{ $batch->batch_number }}</td><td>{{ $batch->location_name }}</td><td>{{ $batch->received_quantity }}</td><td>{{ $batch->available_quantity }}</td><td>{{ $batch->manufactured_at ?: '—' }}</td><td>{{ $batch->expires_at ?: '—' }}</td></tr>
                @empty<tr><td colspan="6"><div class="empty-state"><h3>No batches recorded</h3></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    <div class="content-grid">
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Recent movements</h2><p>Latest immutable ledger entries for this item.</p></div><a href="{{ route('restaurant.inventory.ledger', ['item_id' => $item->id]) }}">Full ledger</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Location</th><th>Type</th><th>In</th><th>Out</th></tr></thead><tbody>
                @forelse($movements as $movement)<tr><td>{{ $movement->occurred_at->format('M j, Y H:i') }}</td><td>{{ $movement->reference ?: $movement->movement_number }}</td><td>{{ $movement->location->name }}</td><td>{{ str($movement->movement_type)->replace('_', ' ')->title() }}</td><td>{{ $movement->quantity_in }}</td><td>{{ $movement->quantity_out }}</td></tr>
                @empty<tr><td colspan="6"><div class="empty-state"><h3>No movement history</h3></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Recipe usage</h2><p>Menu recipes that consume this item.</p></div><a href="{{ route('restaurant.inventory.recipes.index') }}">Manage recipes</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Menu item</th><th>Version</th><th>Quantity per yield</th><th>Status</th></tr></thead><tbody>
                @forelse($recipes as $recipe)<tr><td>{{ $recipe->menu_item_name }}</td><td>v{{ $recipe->version }}</td><td>{{ $recipe->base_quantity }} {{ $item->baseUnit->abbreviation }}</td><td>{{ $recipe->is_active ? 'Active' : 'Historical' }}</td></tr>
                @empty<tr><td colspan="4"><div class="empty-state"><h3>Not used in recipes</h3></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>
    <link rel="stylesheet" href="{{ asset('assets/restaurant-inventory.css') }}?v={{ filemtime(public_path('assets/restaurant-inventory.css')) }}">
@endsection
