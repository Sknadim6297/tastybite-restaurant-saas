@extends('layouts.restaurant-admin')

@section('title', ($order->po_number ?? 'Draft').' · Print Purchase Order')
@section('section', 'Purchase Order')

@section('content')
    <div class="page-heading invoice-print-controls"><div><span class="eyebrow">PURCHASE ORDER</span><h1>{{ $order->po_number ?? 'Draft #'.$order->id }}</h1></div><button class="button button-primary" type="button" data-print-document>Print</button></div>
    <section class="panel-card"><h2>{{ $restaurant->name }}</h2><p>Vendor: {{ $order->vendor->name }} · {{ $order->vendor->vendor_code }}</p><p>Order date: {{ $order->order_date->format('M j, Y') }} · Expected delivery: {{ $order->expected_delivery_date?->format('M j, Y') ?? '—' }}</p>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->item_name }}</td><td>{{ number_format((float) $item->quantity, 3) }}</td><td>{{ $item->unit }}</td><td>INR {{ number_format((float) $item->unit_price, 2) }}</td><td>INR {{ number_format((float) $item->discount_amount, 2) }}</td><td>INR {{ number_format((float) $item->tax_amount, 2) }}</td><td>INR {{ number_format((float) $item->line_total, 2) }}</td></tr>@endforeach</tbody></table></div>
        <p>Subtotal INR {{ number_format((float) $order->subtotal, 2) }} · Discount INR {{ number_format((float) $order->discount_total, 2) }} · Tax INR {{ number_format((float) $order->tax_total, 2) }}</p><h3>Grand total INR {{ number_format((float) $order->grand_total, 2) }}</h3>@if($order->notes)<p>{{ $order->notes }}</p>@endif
    </section>
    <style>@media print {.admin-sidebar,.admin-topbar,.invoice-print-controls,.toast-region,.confirm-dialog{display:none!important}.admin-main{margin:0}.admin-content{padding:0}}</style>
    <script>document.querySelector('[data-print-document]')?.addEventListener('click', () => window.print());</script>
@endsection