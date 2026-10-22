@extends('layouts.restaurant-admin')

@section('title', 'Wastage and Spoilage')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Audited write-offs</span><h1>Wastage and spoilage</h1><p>Posted wastage reduces stock once and records its weighted-average cost.</p></div></div>
    <section class="panel-card"><h2>Record wastage</h2>
        <form method="POST" action="{{ route('restaurant.inventory.wastage.store') }}" class="form-grid">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-field"><label for="item">Inventory item</label><select id="item" name="inventory_item_id" required>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select></div>
            <div class="form-field"><label for="location">Location</label><select id="location" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="quantity">Quantity (base unit)</label><input id="quantity" type="number" name="quantity" min="0.000001" step="0.000001" required></div>
            <div class="form-field"><label for="batch">Batch</label><select id="batch" name="batch_id"><option value="">FEFO automatic selection</option>@foreach($batches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }} · {{ $batch->available_quantity }}</option>@endforeach</select></div>
            <div class="form-field"><label for="reason">Reason</label><select id="reason" name="reason" required><option value="expired">Expired</option><option value="spoiled">Spoiled</option><option value="damaged">Damaged</option><option value="overcooked">Overcooked</option><option value="preparation_waste">Preparation waste</option><option value="other">Other</option></select></div>
            <div class="form-field"><label for="wasted_at">Date</label><input id="wasted_at" type="datetime-local" name="wasted_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
            <div class="form-field form-field-full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2"></textarea></div>
            @foreach(['inventory_item_id', 'location_id', 'quantity', 'batch_id', 'reason', 'wasted_at'] as $field)@error($field)<span class="field-error">{{ $message }}</span>@enderror @endforeach
            <div class="form-field"><button class="button button-primary" type="submit">Post wastage</button></div>
        </form>
    </section>
    <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Item</th><th>Location</th><th>Reason</th><th>Quantity</th><th>Cost</th></tr></thead><tbody>
        @forelse($records as $record)<tr><td>{{ \Illuminate\Support\Carbon::parse($record->wasted_at)->format('M j, Y') }}</td><td>{{ $record->wastage_number }}</td><td>{{ $record->name }}</td><td>{{ $record->location_name }}</td><td>{{ str($record->reason)->replace('_', ' ')->title() }}</td><td>{{ $record->quantity }}</td><td>₹{{ $record->cost }}</td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No wastage recorded</h3><p>Posted wastage records appear here.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($records->hasPages())<div class="page-tools pagination-tools">{{ $records->links() }}</div>@endif</section>
@endsection
