@php
    $invoice = $order->invoice;
    $payment = $order->payments->firstWhere('status', 'recorded');
    $reference = $invoice?->upi_reference
        ?: $invoice?->card_reference
        ?: $payment?->external_reference;
@endphp
<div class="order-row-actions order-invoice-actions">
    <a class="button button-secondary" href="{{ route('restaurant.orders.show', $order) }}">View Order</a>
    @if($invoice)
        <a class="button button-secondary" href="{{ route('restaurant.orders.invoice', $order) }}" target="_blank" rel="noopener">View Invoice</a>
        <a class="button button-secondary" href="{{ route('restaurant.orders.invoice.pdf', $order) }}">Download Invoice</a>
        <a class="button button-secondary" href="{{ route('restaurant.orders.invoice', ['order' => $order, 'print' => 1]) }}" target="_blank" rel="noopener">Print Invoice</a>
        @if($order->status !== 'cancelled')
            <form method="POST" action="{{ route('restaurant.orders.kot.reprint', $order) }}">
                @csrf
                <button class="button button-secondary" type="submit">Reprint KOT</button>
            </form>
        @endif
    @elseif($order->status === 'completed' && $order->fulfillment_status === 'completed')
        <a class="button button-secondary" href="{{ route('restaurant.orders.receipt', $order) }}" target="_blank" rel="noopener">Print Invoice</a>
    @endif
    @if($reference)
        <span class="order-payment-reference">Ref {{ $reference }}</span>
    @endif
</div>
