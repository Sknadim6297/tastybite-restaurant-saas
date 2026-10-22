<!doctype html>
<html lang="en"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#222;font-size:11px}h1{font-size:20px}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}.totals{text-align:right}</style></head><body>
    <h1>{{ $restaurant->name }} · Purchase Order {{ $order->po_number ?? 'Draft #'.$order->id }}</h1>
    <p>Vendor: {{ $order->vendor->name }} ({{ $order->vendor->vendor_code }})</p>
    <p>Order date: {{ $order->order_date->format('M j, Y') }} · Expected delivery: {{ $order->expected_delivery_date?->format('M j, Y') ?? 'Not set' }}</p>
    <table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->item_name }}</td><td>{{ number_format((float) $item->quantity, 3) }}</td><td>{{ $item->unit }}</td><td>{{ number_format((float) $item->unit_price, 2) }}</td><td>{{ number_format((float) $item->discount_amount, 2) }}</td><td>{{ number_format((float) $item->tax_amount, 2) }}</td><td>{{ number_format((float) $item->line_total, 2) }}</td></tr>@endforeach</tbody></table>
    <p class="totals">Subtotal INR {{ number_format((float) $order->subtotal, 2) }}<br>Discount INR {{ number_format((float) $order->discount_total, 2) }}<br>Tax INR {{ number_format((float) $order->tax_total, 2) }}<br><strong>Grand total INR {{ number_format((float) $order->grand_total, 2) }}</strong></p>
    @if($order->notes)<p>Notes: {{ $order->notes }}</p>@endif
</body></html>