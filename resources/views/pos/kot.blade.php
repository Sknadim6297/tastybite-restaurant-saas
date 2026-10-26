<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KOT {{ $kot->kot_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #e9edf2; color: #111; font-family: Arial, sans-serif; }
        .toolbar { max-width: 340px; margin: 0 auto 15px; display: flex; justify-content: center; gap: 8px; }
        .toolbar button { border: 0; padding: 11px 22px; background: #ea580c; color: #fff; border-radius: 6px; cursor: pointer; font-weight: 700; }
        .ticket { width: 80mm; max-width: 100%; margin: auto; padding: 4mm 3mm; background: #fff; font-family: "Courier New", monospace; font-size: 12px; }
        .center { text-align: center; }
        h1 { margin: 0; font-size: 18px; letter-spacing: .5px; overflow-wrap: anywhere; }
        .eyebrow { margin: 2px 0 0; font-size: 11px; letter-spacing: 2px; }
        .dash { border: 0; border-top: 1px dashed #111; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; margin: 4px 0; }
        .row span:last-child { text-align: right; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 3px 0; vertical-align: top; }
        th:last-child, td:last-child { text-align: right; width: 42px; }
        .note { margin: 0 0 6px; font-size: 11px; }
        .reprint { margin-top: 8px; font-size: 11px; text-align: center; }
        @media print {
            @page { size: 80mm auto; margin: 4mm; }
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .ticket { width: auto; max-width: 72mm; }
        }
    </style>
</head>
<body>
    @unless($autoprint ?? false)
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print KOT</button>
        </div>
    @endunless
    <main class="ticket">
        <header class="center">
            <h1>{{ $kot->restaurant_name }}</h1>
            <p class="eyebrow">KITCHEN ORDER TICKET</p>
        </header>
        <hr class="dash">
        <div class="row"><span>KOT No</span><strong>{{ $kot->kot_number }}</strong></div>
        <div class="row"><span>Order No</span><strong>{{ $kot->order_number }}</strong></div>
        <div class="row"><span>Type</span><strong>{{ str($kot->order_type)->replace('_', ' ')->title() }}</strong></div>
        @if($kot->table_number)
            <div class="row"><span>Table</span><strong>{{ $kot->table_number }}</strong></div>
        @endif
        <div class="row"><span>Time</span><span>{{ $kot->printed_at?->timezone(config('app.timezone'))->format('d/m/Y h:i A') }}</span></div>
        <hr class="dash">
        <table>
            <thead>
                <tr><th>Item</th><th>Qty</th></tr>
            </thead>
            <tbody>
                @forelse($kot->items as $item)
                    <tr>
                        <td>{{ $item['name'] ?? 'Item' }}</td>
                        <td>{{ $item['quantity'] ?? 0 }}</td>
                    </tr>
                    @if(!empty($item['instructions']))
                        <tr><td colspan="2"><p class="note">Note: {{ $item['instructions'] }}</p></td></tr>
                    @endif
                @empty
                    <tr><td colspan="2">No items</td></tr>
                @endforelse
            </tbody>
        </table>
        @if((int) $kot->reprint_count > 0)
            <p class="reprint">REPRINT {{ $kot->reprint_count }} · {{ $kot->last_printed_at?->timezone(config('app.timezone'))->format('d/m/Y h:i A') }}</p>
        @endif
    </main>
    @if($autoprint ?? false)
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>
