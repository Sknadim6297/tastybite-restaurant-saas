@extends('layouts.restaurant-admin')

@section('title', $receipt->grn_number.' · Goods Receipt')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $receipt->grn_number }}</span><h1>Goods receipt</h1><p>{{ $receipt->vendor->name }} · Received {{ $receipt->received_date->format('M j, Y') }}</p></div><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.show', $receipt->purchaseOrder) }}">View purchase order</a></div>
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    @if($pendingInventory->isNotEmpty() || $unmappedInventory->isNotEmpty())
        <section class="notice-card"><strong>Inventory posting is pending</strong><p>Map each accepted purchase line explicitly, and provide batch details for tracked items before posting inventory.</p>
            @if($unmappedInventory->isNotEmpty())<p>Unmapped lines: @foreach($unmappedInventory as $line){{ $line->item_name }}@if(!$loop->last), @endif @endforeach</p>@endif
            <a class="button button-secondary" href="{{ route('restaurant.inventory.vendor-mappings.index') }}">Configure vendor item mappings</a>
            @if($pendingInventory->isNotEmpty())<form method="POST" action="{{ route('restaurant.vendors.goods-received.inventory.post', $receipt) }}">@csrf<button class="button button-primary" type="submit">Post mapped items to inventory</button></form>@endif
        </section>
    @else
        <section class="notice-card"><strong>Inventory posting complete</strong><p>All accepted, mapped GRN quantities are recorded in the inventory ledger.</p></section>
    @endif
    <section class="panel-card"><div class="detail-grid"><div class="detail-item"><span>Received by</span><strong>{{ $receipt->receiver?->name ?? '—' }}</strong></div><div class="detail-item"><span>PO number</span><strong>{{ $receipt->purchaseOrder->po_number }}</strong></div>@if($receipt->notes)<div class="detail-item detail-item-wide"><span>Notes</span><strong>{{ $receipt->notes }}</strong></div>@endif</div></section>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Ordered</th><th>Previously received</th><th>Received</th><th>Rejected</th><th>Accepted</th><th>Unit</th></tr></thead><tbody>
        @foreach($receipt->items as $item)<tr><td>{{ $item->item_name }}</td><td>{{ number_format((float) $item->ordered_quantity, 3) }}</td><td>{{ number_format((float) $item->previously_received_quantity, 3) }}</td><td>{{ number_format((float) $item->received_quantity, 3) }}</td><td>{{ number_format((float) $item->rejected_quantity, 3) }}</td><td>{{ number_format((float) $item->accepted_quantity, 3) }}</td><td>{{ $item->unit }}</td></tr>@endforeach
    </tbody></table></div>
@endsection