
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Receipt - {{ $order->order_number }}</title>

    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 20px;
            background: #e9edf2;
            font-family: Arial, sans-serif;
            color: #111;
        }
        .toolbar {
            max-width: 340px;
            margin: 0 auto 15px;
            text-align: center;
        }
        .toolbar button,
        .toolbar a {
            border: 0;
            padding: 11px 22px;
            background: #ea580c;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
        }
        .receipt {
            width: 58mm;
            max-width: 100%;
            margin: auto;
            padding: 4mm 3mm;
            background: #fff;
            font-family: "Courier New", monospace;
            font-size: 11px;
        }
        .center { text-align: center; }
        .logo {
            width: 42px;
            height: 42px;
            border: 2px solid #111;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            font-size: 23px;
        }
        .shop-name {
            font-size: 21px;
            font-weight: bold;
            letter-spacing: 1px;
            overflow-wrap: anywhere;
        }
        .subtitle {
            font-size: 10px;
            letter-spacing: 2px;
            margin-top: 2px;
        }
        .small {
            font-size: 10px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }
        .dash {
            border: 0;
            border-top: 1px dashed #111;
            margin: 8px 0;
        }
        .details {
            display: flex;
            justify-content: space-between;
            gap: 5px;
            margin: 4px 0;
            overflow-wrap: anywhere;
        }
        .details span:last-child,
        .details strong:last-child {
            text-align: right;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .items th {
            border-bottom: 1px dashed #111;
            padding: 5px 1px;
            text-align: left;
        }
        .items td {
            padding: 5px 1px;
            vertical-align: top;
            overflow-wrap: anywhere;
        }
        .items th:nth-child(1),
        .items td:nth-child(1) { width: 48%; }
        .items th:nth-child(2),
        .items td:nth-child(2) {
            width: 14%;
            text-align: center;
        }
        .items th:nth-child(3),
        .items td:nth-child(3) {
            width: 18%;
            text-align: right;
        }
        .items th:nth-child(4),
        .items td:nth-child(4) {
            width: 20%;
            text-align: right;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 5px;
            margin: 5px 0;
            overflow-wrap: anywhere;
        }
        .summary-row span:last-child,
        .summary-row strong:last-child {
            text-align: right;
        }
        .grand {
            font-size: 15px;
            font-weight: bold;
            border-top: 1px dashed #111;
            border-bottom: 1px dashed #111;
            padding: 8px 0;
        }
        .footer {
            text-align: center;
            line-height: 1.6;
            margin-top: 12px;
        }
        @page {
            size: 58mm auto;
            margin: 0;
        }
        @media print {
            body {
                padding: 0;
                background: #fff;
            }
            .toolbar { display: none; }
            .receipt {
                width: 58mm;
                max-width: none;
                margin: 0;
                padding: 3mm;
            }
        }
    </style>
</head>

<body>
{{-- Thermal layout stays on the existing receipt until a design reference is supplied. --}}
@php
    $invoice = $order->invoice;
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $billDate = $invoice?->issued_at ?? $order->completed_at ?? $order->created_at;
    $payment = $order->payments
        ->where('status', 'recorded')
        ->sortByDesc('recorded_at')
        ->first();
    $paymentMethod = $invoice?->payment_method ?? $payment?->method ?? $order->payment_method;
    $orderType = ucwords(str_replace('_', ' ', $order->order_type ?? ''));
    $billName = $invoice?->restaurant_name ?? $restaurant->name;
    $billAddress = $invoice?->restaurant_address ?? $restaurant->address;
    $billPhone = $invoice?->restaurant_phone ?? $restaurant->phone;
    $taxPaise = (int) round(((float) ($invoice?->tax ?? $order->tax)) * 100);
    $cgstPaise = intdiv($taxPaise, 2);
    $sgstPaise = $taxPaise - $cgstPaise;
@endphp

    @unless($pdf ?? false)
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
        @if($invoice)
            <a href="{{ route('restaurant.orders.invoice.pdf', $order) }}">Download PDF</a>
        @endif
    </div>
    @endunless

    <div class="receipt">
        <div class="center">
            <div class="logo">☕</div>

            <div class="shop-name">
                {{ $billName }}
            </div>

            <div class="subtitle">RESTAURANT</div>

            <div class="small">
                @if($billAddress)
                    <div>{{ $billAddress }}</div>
                @endif

                @if($billPhone)
                    <div>Phone: {{ $billPhone }}</div>
                @endif
            </div>
        </div>

        <hr class="dash" />

        <div class="details">
            <span>Bill No:</span>
            <strong>{{ $order->order_number }}</strong>
        </div>

        @if($invoice)
            <div class="details">
                <span>Invoice No:</span>
                <strong>{{ $invoice->invoice_number }}</strong>
            </div>
        @endif

        <div class="details">
            <span>Date:</span>
            <span>{{ $billDate?->format('d/m/Y') ?? '—' }}</span>
        </div>

        <div class="details">
            <span>Time:</span>
            <span>{{ $billDate?->format('h:i A') ?? '—' }}</span>
        </div>

        <div class="details">
            <span>Order Type:</span>
            <strong>{{ $orderType }}</strong>
        </div>

        @if($order->table_number)
            <div class="details">
                <span>Table No:</span>
                <span>{{ $order->table_number }}</span>
            </div>
        @endif

        @if($order->customer_name)
            <div class="details">
                <span>Customer:</span>
                <span>{{ $order->customer_name }}</span>
            </div>
        @endif

        @if($order->customer_mobile)
            <div class="details">
                <span>Mobile:</span>
                <span>{{ $order->customer_mobile }}</span>
            </div>
        @endif

        <hr class="dash" />

        <table class="items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Rate</th>
                    <th>Amt</th>
                </tr>
            </thead>

            <tbody id="billItems">
                @forelse($order->items as $item)
                    <tr>
                        <td>{{ $item->item_name }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $money($item->unit_price) }}</td>
                        <td>
                            {{ $money(
                                (float) $item->unit_price *
                                (float) $item->quantity
                            ) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="center">
                            No items found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <hr class="dash" />

        <div class="summary-row">
            <span>Subtotal</span>
            <span>{{ $money($order->subtotal) }}</span>
        </div>

        <div class="summary-row">
            <span>Discount</span>
            <span>-{{ $money($order->discount) }}</span>
        </div>

        <div class="summary-row">
            <span>CGST (2.5%)</span>
            <span>{{ $money($cgstPaise / 100) }}</span>
        </div>

        <div class="summary-row">
            <span>SGST (2.5%)</span>
            <span>{{ $money($sgstPaise / 100) }}</span>
        </div>

        <div class="summary-row grand">
            <span>GRAND TOTAL</span>
            <span>{{ $money($invoice?->total ?? $order->total) }}</span>
        </div>

        <div class="summary-row">
            <span>Payment Mode</span>
            <strong>
                {{ $paymentMethod ? strtoupper($paymentMethod) : 'N/A' }}
            </strong>
        </div>

        <div class="summary-row">
            <span>Payment Status</span>
            <strong>
                {{ ucwords(str_replace(
                    '_',
                    ' ',
                    $order->payment_status ?? 'unpaid'
                )) }}
            </strong>
        </div>

        @if($payment)
            <div class="summary-row">
                <span>Amount Paid</span>
                <span>{{ $money($payment->amount) }}</span>
            </div>

            @if($invoice?->payment_reference ?: $payment->payment_reference)
                <div class="summary-row">
                    <span>Payment Ref</span>
                    <span>{{ $invoice->payment_reference ?? $payment->payment_reference }}</span>
                </div>
            @endif
        @endif

        @if($paymentMethod === 'cash' && $invoice?->cash_received)
            <div class="summary-row">
                <span>Cash Received</span>
                <span>{{ $money($invoice->cash_received) }}</span>
            </div>
            <div class="summary-row">
                <span>Change</span>
                <span>{{ $money($invoice->change_amount ?? 0) }}</span>
            </div>
        @endif

        @if($paymentMethod === 'upi' && $invoice?->upi_reference)
            <div class="summary-row">
                <span>UPI UTR</span>
                <span>{{ $invoice->upi_reference }}</span>
            </div>
        @endif

        @if($paymentMethod === 'card' && $invoice)
            <div class="summary-row">
                <span>Card</span>
                <span>**** {{ $invoice->card_last_four }}</span>
            </div>
            @if($invoice->card_reference)
                <div class="summary-row">
                    <span>Terminal Ref</span>
                    <span>{{ $invoice->card_reference }}</span>
                </div>
            @endif
        @endif

        <hr class="dash" />

        <div class="footer">
            <strong>Thank You!</strong>
            <div>Please Visit Again</div>
            <span class="small">Computer Generated Bill</span>
        </div>
    </div>
    @if(($autoprint ?? false) && !($pdf ?? false))
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>
