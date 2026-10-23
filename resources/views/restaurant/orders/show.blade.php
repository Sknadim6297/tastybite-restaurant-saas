@extends('layouts.restaurant-admin')

@section('title', 'Order '.$order->order_number)
@section('section', 'Orders Management')

@section('content')
    <div class="page-heading orders-page-heading">
        <div><span class="eyebrow">ORDER DETAILS</span><h1>{{ $order->order_number }}</h1><p>{{ $order->created_at?->format('M j, Y H:i') }} · {{ str($order->order_type)->replace('_', ' ')->title() }}</p></div>
        <div class="orders-heading-actions">
            <a class="button button-secondary" href="{{ url()->previous() }}">Back to orders</a>
            <a class="button button-primary" href="{{ route('restaurant.orders.receipt', $order) }}" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Print receipt</a>
        </div>
    </div>
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice-card order-error-notice" role="alert">{{ $errors->first() }}</div>@endif

    <section class="order-detail-grid">
        <article class="panel-card order-detail-card">
            <div class="orders-section-heading"><div><h2>Order information</h2><p>Restaurant and fulfillment details</p></div><span class="order-status-badge order-status-{{ $order->fulfillment_status }}">{{ str($order->fulfillment_status)->replace('_', ' ')->title() }}</span></div>
            <dl class="order-detail-fields">
                <div><dt>Restaurant</dt><dd>{{ $restaurant->name }}</dd></div>
                <div><dt>Order number</dt><dd>{{ $order->order_number }}</dd></div>
                <div><dt>Order date</dt><dd>{{ $order->created_at?->format('M j, Y H:i:s') }}</dd></div>
                <div><dt>Order type</dt><dd>{{ str($order->order_type)->replace('_', ' ')->title() }}</dd></div>
                <div><dt>Table</dt><dd>{{ $order->table_number ?: '—' }}{{ $order->diningFloor ? ' · '.$order->diningFloor->name : '' }}{{ $order->diningSection ? ' · '.$order->diningSection->name : '' }}</dd></div>
                @if($order->originalDiningTable && $order->originalDiningTable->id !== $order->table_id)
                    <div><dt>Originally assigned</dt><dd>{{ $order->originalDiningTable->table_number }}</dd></div>
                @endif
                <div><dt>Guests</dt><dd>{{ $order->guest_count ?: '—' }}</dd></div>
                <div><dt>Customer</dt><dd>{{ $order->customer_name ?: 'Walk-in' }}</dd></div>
                <div><dt>Contact</dt><dd>{{ $order->customer_mobile ?: '—' }}</dd></div>
                @if($order->delivery_address)<div><dt>Delivery address</dt><dd>{{ $order->delivery_address }}</dd></div>@endif
                <div><dt>Created by</dt><dd>{{ $order->creator?->name ?: '—' }}</dd></div>
                <div><dt>Last updated by</dt><dd>{{ $order->updater?->name ?: '—' }}</dd></div>
                <div><dt>Payment status</dt><dd><span class="order-status-badge order-status-{{ $order->payment_status }}">{{ str($order->payment_status)->replace('_', ' ')->title() }}</span>{{ $order->payment_method ? ' · '.strtoupper($order->payment_method) : '' }}</dd></div>
                @if($order->notes)<div class="order-detail-wide"><dt>Notes</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </article>

        <article class="panel-card order-detail-card">
            <div class="orders-section-heading"><div><h2>Order summary</h2><p>{{ $order->items->sum('quantity') }} items</p></div></div>
            <div class="order-lines">
                @foreach($order->items as $item)
                    <div><span>{{ $item->item_name }} <small>× {{ $item->quantity }}</small></span><strong>₹{{ number_format((float) $item->unit_price * $item->quantity, 2) }}</strong><small>₹{{ number_format((float) $item->unit_price, 2) }} each</small></div>
                @endforeach
            </div>
            <dl class="order-total-lines">
                <div><dt>Subtotal</dt><dd>₹{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                <div><dt>Discount</dt><dd>− ₹{{ number_format((float) $order->discount, 2) }}</dd></div>
                <div><dt>Tax</dt><dd>₹{{ number_format((float) $order->tax, 2) }}</dd></div>
                <div class="order-grand-total"><dt>Grand total</dt><dd>₹{{ number_format((float) $order->total, 2) }}</dd></div>
            </dl>
        </article>
    </section>

    @unless($adminMode)
        @if($order->status === 'completed' && $nextStatuses !== [])
            <section class="panel-card order-management-actions">
                <div><h2>Update fulfillment status</h2><p>Only the next valid status for this order type is accepted.</p></div>
                <form method="POST" action="{{ route('restaurant.orders.status', $order) }}" class="order-status-form">
                    @csrf @method('PATCH')
                    <select name="status" aria-label="New fulfillment status" required>
                        <option value="">Select next status</option>
                        @foreach($nextStatuses as $status)
                            <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                    <input name="reason" maxlength="2000" placeholder="Optional note">
                    <button class="button button-primary" type="submit">Update status</button>
                </form>
            </section>
        @endif
    @endunless

    <section class="panel-card order-history-card">
        <div class="orders-section-heading"><div><h2>Payments &amp; refunds</h2><p>Recorded manually; no external payment or refund is processed by TastyBite.</p></div></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Date</th><th>Type</th><th>Method / Reason</th><th>Amount</th><th>Recorded by</th></tr></thead>
                <tbody>
                    @forelse($order->payments as $payment)
                        <tr><td>{{ $payment->payment_reference }}</td><td>{{ $payment->recorded_at?->format('M j, Y H:i') }}</td><td>Payment</td><td>{{ strtoupper($payment->method) }}</td><td>₹{{ number_format((float) $payment->amount, 2) }}</td><td>{{ $payment->recordedBy?->name ?: '—' }}</td></tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><h3>No payment records</h3><p>This order has no recorded payment.</p></div></td></tr>
                    @endforelse
                    @foreach($order->refunds as $refund)
                        <tr><td>{{ $refund->refund_reference }}</td><td>{{ $refund->recorded_at?->format('M j, Y H:i') }}</td><td>Manual refund</td><td>{{ $refund->reason }}</td><td>− ₹{{ number_format((float) $refund->amount, 2) }}</td><td>{{ $refund->recorder?->name ?: '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @unless($adminMode)
        @if($order->status === 'completed' && $order->payment_status === 'unpaid' && $order->payments->where('status', 'recorded')->isEmpty())
            <section class="panel-card order-management-actions order-payment-reconcile">
                <div>
                    <h2>Reconcile a verified payment</h2>
                    <p>Use only after checking the cash received or confirming the external card/UPI transaction. This records the full order total; it does not process or verify external funds.</p>
                </div>
                <form method="POST" action="{{ route('restaurant.orders.payment.reconcile', $order) }}" class="order-reconcile-form">
                    @csrf
                    <label>Payment method
                        <select name="method" required>
                            <option value="">Select method</option>
                            @foreach(['cash' => 'Cash', 'card' => 'Card', 'upi' => 'UPI / QR'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Verification reference or note
                        <input name="verification_note" minlength="5" maxlength="1000" placeholder="Terminal receipt, UPI reference, or cash verification" required>
                    </label>
                    <label class="payment-reconcile-confirm">
                        <input type="checkbox" name="confirmed" value="1" required>
                        <span>I verified the full payment was received.</span>
                    </label>
                    <button class="button button-primary" type="submit">Record verified payment</button>
                </form>
            </section>
        @endif
    @endunless

    @unless($adminMode)
        @if($order->status === 'completed' && $order->payment_status !== 'unpaid' && $order->payment_status !== 'refunded')
            <section class="panel-card order-management-actions">
                <div><h2>Record a manual refund</h2><p>This records a refund for the audit trail; it does not transfer funds.</p></div>
                <form method="POST" action="{{ route('restaurant.orders.refund', $order) }}" class="order-refund-form">
                    @csrf
                    <label>Amount<input type="number" name="amount" min="0.01" max="{{ $order->total }}" step="0.01" required></label>
                    <label>Reason<input name="reason" minlength="5" maxlength="2000" required></label>
                    <button class="button button-secondary" type="submit" data-confirm="Record this manual refund? TastyBite will not transfer funds.">Record refund</button>
                </form>
            </section>
        @endif
    @endunless

    <section class="panel-card order-history-card">
        <div class="orders-section-heading"><div><h2>Status history</h2><p>Recorded fulfillment status transitions.</p></div></div>
        <div class="order-timeline">
            @forelse($order->statusHistory as $entry)
                <article><span class="order-timeline-dot"></span><div><strong>{{ str($entry->to_status)->replace('_', ' ')->title() }}</strong><time>{{ $entry->changed_at?->format('M j, Y H:i') }}</time>@if($entry->reason)<p>{{ $entry->reason }}</p>@endif<span>{{ $entry->actor_type === 'super_admin' ? 'Super Admin' : 'Restaurant user' }}</span></div></article>
            @empty
                <p class="muted-copy">No status history is available for this order.</p>
            @endforelse
        </div>
    </section>

    @unless($adminMode)
        @if($order->status === 'completed' && $order->payment_status !== 'paid' && $order->payment_status !== 'partially_refunded')
            <section class="panel-card order-management-actions order-cancel-actions">
                <div><h2>Cancel order</h2><p>Any recorded recipe stock issue will be reversed through the inventory ledger.</p></div>
                <form method="POST" action="{{ route('restaurant.orders.cancel', $order) }}" data-confirm="Cancel this order? Existing stock deductions will be reversed with auditable entries.">
                    @csrf
                    <input name="reason" minlength="5" maxlength="2000" placeholder="Required cancellation reason" required>
                    <button class="button button-danger" type="submit">Cancel order</button>
                </form>
            </section>
        @endif
    @endunless
@endsection
