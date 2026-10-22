@extends('layouts.restaurant-admin')

@section('title', 'Vendor Item Mapping')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Explicit procurement mapping</span><h1>Vendor item mapping</h1><p>Map each purchase-order description manually. No free-text match is assumed when goods are received.</p></div><a class="button button-secondary" href="{{ route('restaurant.vendors.goods-received.index') }}">Goods received</a></div>
    <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Purchase line</th><th>Inventory mapping</th><th>Stocking conversion</th><th>Action</th></tr></thead><tbody>
        @forelse($lines as $line)
            <tr><td><strong>{{ $line->vendor_item_name }}</strong><small>{{ $line->po_number }} · {{ $line->vendor_name }} · {{ $line->vendor_unit }}</small>@if($line->inventory_item_name)<span class="status-badge status-badge-success">Mapped</span>@else<span class="status-badge status-badge-warning">Mapping required</span>@endif</td>
                <td colspan="3"><form method="POST" action="{{ route('restaurant.inventory.vendor-mappings.update', $line->purchase_order_item_id) }}" class="inline-form">@csrf @method('PUT')
                    <label class="visually-hidden" for="inventory-item-{{ $line->purchase_order_item_id }}">Inventory item</label>
                    <select id="inventory-item-{{ $line->purchase_order_item_id }}" name="inventory_item_id" required><option value="">Choose inventory item</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected($line->inventory_item_id == $item->id)>{{ $item->name }} ({{ $item->item_code }})</option>@endforeach</select>
                    <label class="visually-hidden" for="location-{{ $line->purchase_order_item_id }}">Stock location</label>
                    <select id="location-{{ $line->purchase_order_item_id }}" name="location_id" required><option value="">Stock location</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($line->location_id == $location->id)>{{ $location->name }}</option>@endforeach</select>
                    <label class="visually-hidden" for="unit-{{ $line->purchase_order_item_id }}">Vendor purchase unit</label>
                    <select id="unit-{{ $line->purchase_order_item_id }}" name="unit_id" required><option value="">Unit represented by vendor line</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected($line->unit_id == $unit->id)>{{ $unit->name }} ({{ $unit->abbreviation }})</option>@endforeach</select>
                    <label for="factor-{{ $line->purchase_order_item_id }}">Base units per {{ $line->vendor_unit }}</label>
                    <input id="factor-{{ $line->purchase_order_item_id }}" name="base_quantity_per_unit" type="number" min="0.000000001" step="0.000000001" value="{{ $line->base_quantity_per_unit ?? '1' }}" required>
                    <button class="button button-secondary" type="submit">{{ $line->inventory_item_id ? 'Update mapping' : 'Save mapping' }}</button>
                    @error('inventory_item_id')<span class="field-error">{{ $message }}</span>@enderror
                    @error('location_id')<span class="field-error">{{ $message }}</span>@enderror
                    @error('unit_id')<span class="field-error">{{ $message }}</span>@enderror
                    @error('base_quantity_per_unit')<span class="field-error">{{ $message }}</span>@enderror
                </form></td>
            </tr>
        @empty<tr><td colspan="4"><div class="empty-state"><h3>No purchase lines to map</h3><p>Purchase-order items will appear here after vendors are added.</p></div></td></tr>@endforelse
    </tbody></table></div></section>
@endsection
