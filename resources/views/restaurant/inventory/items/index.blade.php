@extends('layouts.restaurant-admin')

@section('title', 'Inventory Items')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Raw materials and supplies</span><h1>Inventory items</h1><p>Manage stock items and view current location balances.</p></div><div class="page-tools"><a class="button button-secondary" href="{{ route('restaurant.inventory.items.export', request()->query()) }}">Export CSV</a><a class="button button-primary" href="{{ route('restaurant.inventory.items.create') }}">Add item</a></div></div>
    <section class="panel-card">
        <form method="GET" class="filter-bar">
            <div class="form-field"><label for="item-search">Search</label><input id="item-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, code or SKU"></div>
            <div class="form-field"><label for="item-category">Category</label><select id="item-category" name="category_id"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="item-stock">Stock status</label><select id="item-stock" name="stock"><option value="">All</option><option value="low" @selected(($filters['stock'] ?? '') === 'low')>Low stock</option><option value="out" @selected(($filters['stock'] ?? '') === 'out')>Out of stock</option><option value="available" @selected(($filters['stock'] ?? '') === 'available')>Available</option></select></div>
            <div class="form-field"><label for="item-status">Status</label><select id="item-status" name="status"><option value="">All</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select></div>
            <button class="button button-secondary" type="submit">Apply filters</button><a class="button button-secondary" href="{{ route('restaurant.inventory.items.index') }}">Reset</a>
        </form>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Item Code</th><th>Item Name</th><th>Category</th><th>Current Stock</th><th>Average Cost</th><th>Stock Value</th><th>Reorder Level</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($items as $item)
                <tr>
                    <td>{{ $item->item_code }}<small>{{ $item->sku ?: 'No SKU' }}</small></td>
                    <td><strong><a href="{{ route('restaurant.inventory.items.show', $item) }}">{{ $item->name }}</a></strong></td><td>{{ $item->category->name }}</td>
                    <td>{{ \Brick\Math\BigDecimal::of($item->current_quantity)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $item->baseUnit->abbreviation }}</td>
                    <td>₹{{ \Brick\Math\BigDecimal::of($item->average_unit_cost)->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</td>
                    <td>₹{{ $item->stock_value }}</td>
                    <td>{{ \Brick\Math\BigDecimal::of($item->reorder_level)->toScale(3, \Brick\Math\RoundingMode::HALF_UP) }} {{ $item->baseUnit->abbreviation }}</td>
                    <td><span class="status-badge {{ $item->status === 'active' ? 'status-badge-success' : 'status-badge-muted' }}">{{ ucfirst($item->status) }}</span></td>
                    <td><div class="table-actions"><a href="{{ route('restaurant.inventory.items.edit', $item) }}">Edit</a><form method="POST" action="{{ route('restaurant.inventory.items.archive', $item) }}" data-confirm="Archive this inventory item? Its stock history will remain.">@csrf @method('DELETE')<button type="submit" class="text-button">Archive</button></form></div></td>
                </tr>
            @empty<tr><td colspan="9"><div class="empty-state"><h3>No inventory items found</h3><p>Add your first stock item to start tracking inventory.</p><a class="button button-primary" href="{{ route('restaurant.inventory.items.create') }}">Add item</a></div></td></tr>@endforelse
        </tbody></table></div>
        @if($items->hasPages())<div class="page-tools pagination-tools">{{ $items->links() }}</div>@endif
    </section>
@endsection
