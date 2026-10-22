@extends('layouts.restaurant-admin')

@section('title', 'Inventory Reports')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Tenant-scoped reporting</span><h1>Inventory reports</h1><p>Review stock, movement, valuation, wastage, consumption, and expiry records.</p></div><div class="page-tools"><button class="button button-secondary" type="button" data-print-report>Print report</button><a class="button button-secondary" href="{{ route('restaurant.inventory.export', request()->query()) }}">Export report CSV</a></div></div>
    <section class="panel-card">
        <form method="GET" class="filter-bar">
            <div class="form-field"><label for="report">Report</label><select id="report" name="report">@foreach(['stock' => 'Current stock', 'movement' => 'Movement history', 'valuation' => 'Valuation', 'low' => 'Low stock', 'expiry' => 'Expiry', 'wastage' => 'Wastage', 'consumption' => 'Consumption', 'receipts' => 'Vendor receipts', 'adjustments' => 'Adjustments', 'transfers' => 'Transfers', 'counts' => 'Stock count variances', 'stock_in' => 'Stock in', 'stock_out' => 'Stock out', 'ingredient_costs' => 'Recipe ingredient costs'] as $key => $label)<option value="{{ $key }}" @selected($report === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="form-field"><label for="date-from">From</label><input id="date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="form-field"><label for="date-to">To</label><input id="date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
            <div class="form-field"><label for="item_id">Item</label><select id="item_id" name="item_id"><option value="">All items</option>@foreach($filtersItems as $item)<option value="{{ $item->id }}" @selected(($filters['item_id'] ?? '') == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="category_id">Category</label><select id="category_id" name="category_id"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="location_id">Location</label><select id="location_id" name="location_id"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="movement_type">Movement type</label><select id="movement_type" name="movement_type"><option value="">All types</option>@foreach(['opening', 'manual_receipt', 'purchase_receipt', 'consumption', 'manual_issue', 'wastage', 'transfer_in', 'transfer_out', 'adjustment_in', 'adjustment_out', 'reversal'] as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            @if($report === 'receipts')
                <div class="form-field"><label for="vendor_id">Vendor</label><select id="vendor_id" name="vendor_id"><option value="">All vendors</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(($filters['vendor_id'] ?? '') == $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
            @endif
            <button class="button button-secondary" type="submit">Run report</button><a class="button button-secondary" href="{{ route('restaurant.inventory.reports') }}">Reset</a>
        </form>
        @if(in_array($report, ['stock', 'valuation', 'low'], true))
            <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Category</th><th>Location</th><th>Quantity</th><th>Average cost</th><th>Stock value</th><th>Reorder level</th></tr></thead><tbody>
                @forelse($items as $item)
                    @foreach($item->balances->when(isset($filters['location_id']), fn ($rows) => $rows->where('location_id', (int) $filters['location_id'])) as $balance)
                        <tr><td>{{ $item->name }}</td><td>{{ $item->category->name }}</td><td>{{ $balance->location->name }}</td><td>{{ $balance->quantity }} {{ $item->baseUnit->abbreviation }}</td><td>₹{{ $balance->average_unit_cost }}</td><td>₹{{ \Brick\Math\BigDecimal::of($balance->quantity)->multipliedBy($balance->average_unit_cost)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</td><td>{{ $item->reorder_level }}</td></tr>
                    @endforeach
                @empty<tr><td colspan="7"><div class="empty-state"><h3>No stock data</h3><p>Current balances appear after a stock movement is recorded.</p></div></td></tr>@endforelse
            </tbody></table></div>
        @elseif($report === 'ingredient_costs')
            <div class="table-shell"><table class="data-table"><thead><tr><th>Menu item</th><th>Recipe version</th><th>Ingredients</th><th>Cost per yield</th><th>Cost per serving</th></tr></thead><tbody>
                @forelse($recipeCostRows as $row)<tr><td>{{ $row['recipe']->menuItem->name }}</td><td>v{{ $row['recipe']->version }}</td><td>{{ $row['ingredients'] }}</td><td>₹{{ $row['cost_per_yield'] }}</td><td>₹{{ $row['cost_per_serving'] }}</td></tr>
                @empty<tr><td colspan="5"><div class="empty-state"><h3>No active recipes to calculate</h3><p>Create a recipe to report its current ingredient cost.</p></div></td></tr>@endforelse
            </tbody></table></div>
        @elseif($report === 'expiry')
            <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Batch</th><th>Location</th><th>Expiry</th><th>Available</th></tr></thead><tbody>@forelse($items as $batch)<tr><td>{{ $batch->name }}</td><td>{{ $batch->batch_number }}</td><td>{{ $batch->location_name }}</td><td>{{ $batch->expires_at }}</td><td>{{ $batch->available_quantity }}</td></tr>@empty<tr><td colspan="5"><div class="empty-state"><h3>No tracked expiry stock</h3></div></td></tr>@endforelse</tbody></table></div>
        @elseif($report === 'counts')
            <div class="table-shell"><table class="data-table"><thead><tr><th>Count</th><th>Date</th><th>Item</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>@forelse($countVariances as $variance)<tr><td>{{ $variance->count_number }}</td><td>{{ $variance->counted_at }}</td><td>{{ $variance->item_name }}</td><td>{{ $variance->expected_quantity }} {{ $variance->abbreviation }}</td><td>{{ $variance->actual_quantity }} {{ $variance->abbreviation }}</td><td>{{ \Brick\Math\BigDecimal::of($variance->actual_quantity)->minus($variance->expected_quantity)->toScale(6) }} {{ $variance->abbreviation }}</td></tr>@empty<tr><td colspan="6"><div class="empty-state"><h3>No stock count variances</h3></div></td></tr>@endforelse</tbody></table></div>
        @else
            <div class="table-shell"><table class="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Item</th><th>Location</th><th>Type</th><th>In</th><th>Out</th><th>Cost</th><th>Value</th></tr></thead><tbody>@forelse($movements as $movement)<tr><td>{{ $movement->occurred_at->format('M j, Y') }}</td><td>{{ $movement->reference ?: $movement->movement_number }}</td><td>{{ $movement->item->name }}</td><td>{{ $movement->location->name }}</td><td>{{ str($movement->movement_type)->replace('_', ' ')->title() }}</td><td>{{ $movement->quantity_in }}</td><td>{{ $movement->quantity_out }}</td><td>{{ $movement->unit_cost }}</td><td>{{ $movement->value }}</td></tr>@empty<tr><td colspan="9"><div class="empty-state"><h3>No movement records</h3></div></td></tr>@endforelse</tbody></table></div>
        @endif
    </section>
    <link rel="stylesheet" href="{{ asset('assets/restaurant-inventory.css') }}?v={{ filemtime(public_path('assets/restaurant-inventory.css')) }}">
    <script src="{{ asset('assets/restaurant-inventory.js') }}?v={{ filemtime(public_path('assets/restaurant-inventory.js')) }}" defer></script>
@endsection
