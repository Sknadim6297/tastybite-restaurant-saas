@extends('layouts.restaurant-admin')

@section('title', 'Inventory Overview')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">Stock at a glance</span><h1>Inventory overview</h1><p>Current stock health and recent movement across your restaurant.</p></div>
        <div class="toolbar-actions inventory-quick-actions">
            <a class="button button-secondary" href="{{ route('restaurant.inventory.items.create') }}">Add inventory item</a>
            <a class="button button-primary" href="{{ route('restaurant.inventory.stock.create', ['kind' => 'in']) }}">Record stock in</a>
            <a class="button button-secondary" href="{{ route('restaurant.inventory.stock.create', ['kind' => 'out']) }}">Record stock out</a>
            <a class="button button-secondary" href="{{ route('restaurant.inventory.reports') }}">View reports</a>
        </div>
    </div>

    <div class="stats-grid">
        <article class="stat-card"><span>Total inventory items</span><i class="metric-icon bi bi-boxes" aria-hidden="true"></i><strong>{{ number_format($totalItems) }}</strong><a href="{{ route('restaurant.inventory.items.index') }}">View items</a></article>
        <article class="stat-card"><span>Stock value</span><i class="metric-icon bi bi-currency-rupee" aria-hidden="true"></i><strong>₹{{ \Brick\Math\BigDecimal::of((string) $stockValue)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</strong><small>Weighted average valuation</small></article>
        <article class="stat-card"><span>Low stock alerts</span><i class="metric-icon bi bi-exclamation-triangle" aria-hidden="true"></i><strong>{{ $lowStockAlertsEnabled ? number_format($lowStockCount) : 'Off' }}</strong>@if($lowStockAlertsEnabled)<a href="{{ route('restaurant.inventory.reports', ['report' => 'low']) }}">Review low stock</a>@endif</article>
        <article class="stat-card"><span>Out of stock</span><i class="metric-icon bi bi-box" aria-hidden="true"></i><strong>{{ number_format($outOfStockCount) }}</strong></article>
        <article class="stat-card"><span>Expiring within {{ $expiryWarningDays }} days</span><i class="metric-icon bi bi-hourglass-split" aria-hidden="true"></i><strong>{{ $expiryAlertsEnabled ? number_format($expiringCount) : 'Off' }}</strong>@if($expiryAlertsEnabled)<a href="{{ route('restaurant.inventory.batches') }}">View batches</a>@endif</article>
        <article class="stat-card"><span>Expired stock</span><i class="metric-icon bi bi-calendar-x" aria-hidden="true"></i><strong>{{ number_format($expiredCount) }}</strong></article>
        <article class="stat-card"><span>Stock in this month</span><i class="metric-icon bi bi-box-arrow-in-down" aria-hidden="true"></i><strong>{{ $receivedThisMonth }}</strong></article>
        <article class="stat-card"><span>Stock issued this month</span><i class="metric-icon bi bi-box-arrow-up" aria-hidden="true"></i><strong>{{ $consumedThisMonth }}</strong></article>
        <article class="stat-card"><span>Wastage value this month</span><i class="metric-icon bi bi-trash" aria-hidden="true"></i><strong>₹{{ \Brick\Math\BigDecimal::of((string) $wastageValue)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</strong></article>
        <article class="stat-card"><span>Pending stock counts</span><i class="metric-icon bi bi-clipboard-check" aria-hidden="true"></i><strong>{{ number_format($pendingAdjustments) }}</strong><a href="{{ route('restaurant.inventory.counts.index') }}">Review counts</a></article>
    </div>

    <section class="panel-card">
        <div class="panel-heading"><div><h2>Stock value trend</h2><p>Month-end values reconstructed from posted ledger movements.</p></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Month</th><th>Closing stock value</th></tr></thead><tbody>
            @foreach($stockValueTrend as $point)<tr><td>{{ $point['label'] }}</td><td>₹{{ $point['value'] }}</td></tr>@endforeach
        </tbody></table></div>
    </section>

    <div class="content-grid">
        @if($lowStockAlertsEnabled)
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Low stock summary</h2><p>Items at or below their reorder level.</p></div><a href="{{ route('restaurant.inventory.reports', ['report' => 'low']) }}">View report</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Available</th><th>Reorder level</th><th>Status</th></tr></thead><tbody>
                @forelse($lowStock as $row)
                    <tr><td><strong>{{ $row->name }}</strong><small>{{ $row->item_code }}</small></td><td>{{ \Brick\Math\BigDecimal::of((string) $row->current_quantity)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $row->abbreviation }}</td><td>{{ \Brick\Math\BigDecimal::of((string) $row->reorder_level)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $row->abbreviation }}</td><td><span class="status-badge status-badge-warning">Reorder</span></td></tr>
                @empty<tr><td colspan="4"><div class="empty-state"><h3>Stock levels look good</h3><p>Items below reorder level will appear here.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        @endif
        @if($expiryAlertsEnabled)
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Expiring batches</h2><p>Available lots expiring soon.</p></div><a href="{{ route('restaurant.inventory.batches') }}">All batches</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Item / Batch</th><th>Location</th><th>Available</th><th>Expires</th></tr></thead><tbody>
                @forelse($expiring as $batch)
                    <tr><td><strong>{{ $batch->item_name }}</strong><small>{{ $batch->batch_number }}</small></td><td>{{ $batch->location_name }}</td><td>{{ \Brick\Math\BigDecimal::of((string) $batch->available_quantity)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }}</td><td>{{ \Illuminate\Support\Carbon::parse($batch->expires_at)->format('M j, Y') }}</td></tr>
                @empty<tr><td colspan="4"><div class="empty-state"><h3>No expiring batches</h3><p>Tracked batches will appear here.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        @endif
    </div>

    <div class="content-grid">
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Top consumed ingredients</h2><p>Highest recorded ingredient issues.</p></div><a href="{{ route('restaurant.inventory.reports', ['report' => 'consumption']) }}">View consumption</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Quantity</th></tr></thead><tbody>
                @forelse($topConsumed as $item)<tr><td>{{ $item->name }}</td><td>{{ \Brick\Math\BigDecimal::of((string) $item->consumed_quantity)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $item->abbreviation }}</td></tr>
                @empty<tr><td colspan="2"><div class="empty-state"><h3>No ingredient consumption yet</h3><p>Active recipes and completed sales build this summary.</p><a href="{{ route('restaurant.inventory.recipes.index') }}">Review recipes</a></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section class="panel-card">
            <div class="panel-heading"><div><h2>Recent goods receipts</h2><p>Vendor deliveries recorded most recently.</p></div><a href="{{ route('restaurant.vendors.goods-received.index') }}">All receipts</a></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>GRN</th><th>Vendor</th><th>Date</th></tr></thead><tbody>
                @forelse($recentReceipts as $receipt)<tr><td><a href="{{ route('restaurant.vendors.goods-received.show', $receipt->id) }}">{{ $receipt->grn_number }}</a></td><td>{{ $receipt->vendor_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($receipt->received_date)->format('M j, Y') }}</td></tr>
                @empty<tr><td colspan="3"><div class="empty-state"><h3>No goods receipts yet</h3></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <section class="panel-card">
        <div class="panel-heading"><div><h2>Recent stock movements</h2><p>Immutable ledger entries across all locations.</p></div><a href="{{ route('restaurant.inventory.ledger') }}">Open stock ledger</a></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Item</th><th>Location</th><th>Movement</th><th>In</th><th>Out</th></tr></thead><tbody>
            @forelse($recentMovements as $movement)
                <tr><td>{{ $movement->occurred_at->format('M j, Y H:i') }}</td><td>{{ $movement->reference ?: $movement->movement_number }}</td><td>{{ $movement->item->name }}</td><td>{{ $movement->location->name }}</td><td>{{ str($movement->movement_type)->replace('_', ' ')->title() }}</td><td>{{ \Brick\Math\BigDecimal::of((string) $movement->quantity_in)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }}</td><td>{{ \Brick\Math\BigDecimal::of((string) $movement->quantity_out)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }}</td></tr>
            @empty<tr><td colspan="7"><div class="empty-state"><h3>No stock movements yet</h3><p>Opening stock and receipts will appear here.</p><a class="button button-secondary" href="{{ route('restaurant.inventory.stock.create', ['kind' => 'opening']) }}">Record opening stock</a></div></td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection
