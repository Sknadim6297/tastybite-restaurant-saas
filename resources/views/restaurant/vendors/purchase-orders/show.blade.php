@extends('layouts.restaurant-admin')

@section('title', ($order->po_number ?? 'Draft').' · Purchase Order')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $order->po_number ?? 'Draft #'.$order->id }}</span><h1>{{ $order->vendor->name }}</h1><p>Order date {{ $order->order_date->format('M j, Y') }} · Expected {{ $order->expected_delivery_date?->format('M j, Y') ?? 'Not set' }}</p></div><div class="toolbar-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.print', $order) }}">Print</a><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.pdf', $order) }}">Download PDF</a><span class="status-pill status-{{ $order->status === 'received' ? 'active' : (str_contains($order->status, 'received') ? 'pending' : ($order->status === 'cancelled' ? 'disabled' : 'pending')) }}">{{ \Illuminate\Support\Str::headline($order->status) }}</span></div></div>
    <section class="panel-card"><div class="detail-grid"><div class="detail-item"><span>Vendor code</span><strong>{{ $order->vendor->vendor_code }}</strong></div><div class="detail-item"><span>Created by</span><strong>{{ $order->creator?->name ?? '—' }}</strong></div><div class="detail-item"><span>Subtotal</span><strong>INR {{ number_format((float) $order->subtotal, 2) }}</strong></div><div class="detail-item"><span>Discount</span><strong>INR {{ number_format((float) $order->discount_total, 2) }}</strong></div><div class="detail-item"><span>Tax</span><strong>INR {{ number_format((float) $order->tax_total, 2) }}</strong></div><div class="detail-item"><span>Grand total</span><strong>INR {{ number_format((float) $order->grand_total, 2) }}</strong></div></div>@if($order->notes)<p>{{ $order->notes }}</p>@endif</section>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Line total</th><th>Received</th><th>Pending</th></tr></thead><tbody>
        @foreach($order->items as $item)
            @php($receivedMilli = (int) round((float) $item->receiptItems()->sum('received_quantity') * 1000))
            @php($orderedMilli = (int) round((float) $item->quantity * 1000))
            <tr><td>{{ $item->item_name }}</td><td>{{ number_format((float) $item->quantity, 3) }}</td><td>{{ $item->unit }}</td><td>INR {{ number_format((float) $item->unit_price, 2) }}</td><td>INR {{ number_format((float) $item->discount_amount, 2) }}</td><td>INR {{ number_format((float) $item->tax_amount, 2) }}</td><td>INR {{ number_format((float) $item->line_total, 2) }}</td><td>{{ number_format($receivedMilli / 1000, 3) }}</td><td>{{ number_format(max(0, $orderedMilli - $receivedMilli) / 1000, 3) }}</td></tr>
        @endforeach
    </tbody></table></div>
    <section class="panel-card"><div class="toolbar-actions">
        @if($order->status === 'draft')<a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.edit', $order) }}">Edit draft</a><form method="POST" action="{{ route('restaurant.vendors.purchase-orders.confirm', $order) }}" data-confirm="Confirm this purchase order?">@csrf<button class="button button-primary" type="submit">Confirm order</button></form>@endif
        @if(in_array($order->status, ['confirmed', 'partially_received'], true))<a class="button button-primary" href="{{ route('restaurant.vendors.goods-received.create', $order) }}">Receive goods</a>@endif
        @if(in_array($order->status, ['draft', 'confirmed'], true))<form method="POST" action="{{ route('restaurant.vendors.purchase-orders.cancel', $order) }}" data-confirm="Cancel this order? Orders with receipt activity cannot be cancelled.">@csrf<button class="button button-danger" type="submit">Cancel order</button></form>@endif
        <a class="button button-secondary" href="{{ route('restaurant.vendors.goods-received.index', ['vendor_id' => $order->vendor_id]) }}">Goods receipt history</a>
    </div></section>
    <script>document.querySelectorAll('[data-print-document]').forEach(button => button.addEventListener('click', () => window.print()));</script>
@endsection