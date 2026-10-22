@extends('layouts.restaurant-admin')

@section('title', 'Receive Goods')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $order->po_number }}</span><h1>Receive goods</h1><p>{{ $order->vendor->name }} · Ordered {{ $order->order_date->format('M j, Y') }}</p></div></div>
    <form method="POST" action="{{ route('restaurant.vendors.goods-received.store', $order) }}" class="form-stack">
        @csrf<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        <section class="panel-card"><div class="form-grid"><div class="form-field"><label for="grn-date">Received date</label><input id="grn-date" type="date" name="received_date" value="{{ old('received_date', today()->format('Y-m-d')) }}" required>@error('received_date')<span class="field-error">{{ $message }}</span>@enderror</div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Ordered</th><th>Previously received</th><th>Outstanding</th><th>Received now</th><th>Rejected / damaged</th><th>Batch no.</th><th>Manufactured</th><th>Expires</th></tr></thead><tbody>
            @foreach($order->items as $item)
                @php($previousMilli = $received[$item->id] ?? 0)
                @php($outstandingMilli = max(0, (int) round((float) $item->quantity * 1000) - $previousMilli))
                <tr><td>{{ $item->item_name }}<br><span class="meta-label">{{ $item->unit }}</span></td><td>{{ number_format((float) $item->quantity, 3) }}</td><td>{{ number_format($previousMilli / 1000, 3) }}</td><td>{{ number_format($outstandingMilli / 1000, 3) }}</td><td><input type="hidden" name="items[{{ $loop->index }}][purchase_order_item_id]" value="{{ $item->id }}"><input aria-label="Received quantity for {{ $item->item_name }}" name="items[{{ $loop->index }}][received_quantity]" type="number" min="0" max="{{ number_format($outstandingMilli / 1000, 3, '.', '') }}" step="0.001" value="{{ old('items.'.$loop->index.'.received_quantity', '0.000') }}"></td><td><input aria-label="Rejected quantity for {{ $item->item_name }}" name="items[{{ $loop->index }}][rejected_quantity]" type="number" min="0" step="0.001" value="{{ old('items.'.$loop->index.'.rejected_quantity', '0.000') }}"></td><td><input aria-label="Batch number for {{ $item->item_name }}" name="items[{{ $loop->index }}][batch_number]" maxlength="80" value="{{ old('items.'.$loop->index.'.batch_number') }}"></td><td><input aria-label="Manufactured date for {{ $item->item_name }}" name="items[{{ $loop->index }}][manufactured_at]" type="date" value="{{ old('items.'.$loop->index.'.manufactured_at') }}"></td><td><input aria-label="Expiry date for {{ $item->item_name }}" name="items[{{ $loop->index }}][expires_at]" type="date" value="{{ old('items.'.$loop->index.'.expires_at') }}"></td></tr>
            @endforeach
        </tbody></table></div>@error('items')<span class="field-error">{{ $message }}</span>@enderror
        @foreach(['batch_number', 'manufactured_at', 'expires_at'] as $field)@foreach($errors->get('items.*.'.$field) as $messages)@foreach($messages as $message)<span class="field-error">{{ $message }}</span>@endforeach @endforeach @endforeach
        <div class="form-field"><label for="grn-notes">Notes</label><textarea id="grn-notes" name="notes" rows="3" maxlength="5000">{{ old('notes') }}</textarea>@error('notes')<span class="field-error">{{ $message }}</span>@enderror</div></section>
        <div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.show', $order) }}">Cancel</a><button class="button button-primary" type="submit">Save goods receipt</button></div>
    </form>
@endsection