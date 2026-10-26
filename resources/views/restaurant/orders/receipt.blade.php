<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $order->order_number }}</title>
    @include('components.app-fonts')
    <style>
        body{margin:0;background:#f5f6f8;color:#182230;font:13px/1.5 var(--app-font)}
        .receipt{width:min(100%,420px);margin:24px auto;padding:24px;background:#fff}
        h1,h2,p{margin:0 0 8px}h1{font-size:20px}h2{font-size:15px}
        .muted{color:#667085}.center{text-align:center}.rule{border:0;border-top:1px dashed #98a2b3;margin:14px 0}
        .receipt-row{display:flex;justify-content:space-between;gap:12px;margin:5px 0}
        .receipt-row span:first-child{min-width:0}.receipt-total{font-size:16px;font-weight:700}
        .print-action{display:block;margin:0 auto 14px;padding:10px 20px;border:0;border-radius:8px;background:#f28c00;color:#fff;font-weight:700}
        @media print{@page{size:80mm auto;margin:4mm}body{background:#fff}.receipt{width:auto;max-width:72mm;margin:0;padding:0}.print-action{display:none}}
        @media print and (max-width:360px){@page{size:58mm auto}.receipt{max-width:52mm}}
    </style>
</head>
<body>
    <button class="print-action" type="button" onclick="window.print()">Print receipt</button>
    <main class="receipt">
        <header class="center"><h1>{{ $restaurant->name }}</h1>@if($restaurant->address)<p>{{ $restaurant->address }}</p>@endif @if($restaurant->phone)<p>{{ $restaurant->phone }}</p>@endif</header>
        <hr class="rule">
        <p><strong>Order:</strong> {{ $order->order_number }}</p>
        <p><strong>Date:</strong> {{ $order->created_at?->format('M j, Y H:i') }}</p>
        <p><strong>Type:</strong> {{ str($order->order_type)->replace('_', ' ')->title() }}</p>
        @if($order->table_number)<p><strong>Table:</strong> {{ $order->table_number }} · {{ $order->guest_count ?: '—' }} guests</p>@endif
        <hr class="rule">
        @foreach($order->items as $item)
            <div class="receipt-row"><span>{{ $item->item_name }} × {{ $item->quantity }}</span><strong>₹{{ number_format((float) $item->unit_price * $item->quantity, 2) }}</strong></div>
        @endforeach
        <hr class="rule">
        <div class="receipt-row"><span>Subtotal</span><span>₹{{ number_format((float) $order->subtotal, 2) }}</span></div>
        <div class="receipt-row"><span>Discount</span><span>− ₹{{ number_format((float) $order->discount, 2) }}</span></div>
        <div class="receipt-row"><span>Tax</span><span>₹{{ number_format((float) $order->tax, 2) }}</span></div>
        <div class="receipt-row receipt-total"><span>Total</span><span>₹{{ number_format((float) $order->total, 2) }}</span></div>
        <hr class="rule">
        <p><strong>Payment:</strong> {{ $order->payment_method ? strtoupper($order->payment_method) : 'Not recorded' }} · {{ str($order->payment_status)->replace('_', ' ')->title() }}</p>
        @if($order->notes)<p><strong>Notes:</strong> {{ $order->notes }}</p>@endif
        <hr class="rule"><p class="center muted">Thank you for dining with us.</p>
    </main>
</body>
</html>
