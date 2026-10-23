<div class="table-shell">
    <table class="data-table order-data-table">
        <thead>
            <tr>
                @if($kind === 'billing')
                    <th>Order / Payment Ref</th><th>Date</th><th>Customer</th><th>Order Total</th><th>Amount Paid</th><th>Refunded</th><th>Method</th><th>Payment Status</th><th>Action</th>
                @else
                    <th>Order Number</th><th>Date &amp; Time</th><th>Type</th><th>Table / Customer</th><th>Items</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Grand Total</th><th>Payment Status</th><th>Order Status</th><th>Created By</th><th>Actions</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                @php
                    $payments = $order->payments;
                    $paidTotal = $payments->where('status', 'recorded')->sum('amount');
                    $refundTotal = $order->refunds->where('status', 'recorded')->sum('amount');
                @endphp
                <tr>
                    @if($kind === 'billing')
                        <td><strong>{{ $order->order_number }}</strong><small>{{ $payments->where('status', 'recorded')->first()?->payment_reference ?? 'No payment reference' }}</small></td>
                        <td>{{ $order->created_at?->format('M j, Y H:i') }}</td>
                        <td>{{ $order->customer_name ?: 'Walk-in' }}</td>
                        <td>₹{{ number_format((float) $order->total, 2) }}</td>
                        <td>₹{{ number_format((float) $paidTotal, 2) }}</td>
                        <td>₹{{ number_format((float) $refundTotal, 2) }}</td>
                        <td>{{ $order->payment_method ? strtoupper($order->payment_method) : '—' }}</td>
                        <td><span class="order-status-badge order-status-{{ $order->payment_status }}">{{ str($order->payment_status)->replace('_', ' ')->title() }}</span></td>
                        <td><a class="button button-secondary" href="{{ route('restaurant.orders.show', $order) }}">View</a></td>
                    @else
                        <td><strong>{{ $order->order_number }}</strong><small>{{ $order->items_count }} item line(s)</small></td>
                        <td>{{ $order->created_at?->format('M j, Y H:i') }}</td>
                        <td>{{ str($order->order_type)->replace('_', ' ')->title() }}</td>
                        <td>
                            @if($order->order_type === 'dine_in')
                                Table {{ $order->table_number ?: '—' }}
                                <small>{{ $order->diningFloor?->name }}{{ $order->diningSection ? ' · '.$order->diningSection->name : '' }}{{ $order->guest_count ? ' · '.$order->guest_count.' guests' : '' }}</small>
                            @else
                                {{ $order->customer_name ?: 'Walk-in' }}
                            @endif
                        </td>
                        <td class="order-item-summary">@foreach($order->items as $item){{ $item->item_name }} × {{ $item->quantity }}@if(! $loop->last), @endif @endforeach</td>
                        <td>₹{{ number_format((float) $order->subtotal, 2) }}</td>
                        <td>₹{{ number_format((float) $order->discount, 2) }}</td>
                        <td>₹{{ number_format((float) $order->tax, 2) }}</td>
                        <td><strong>₹{{ number_format((float) $order->total, 2) }}</strong></td>
                        <td><span class="order-status-badge order-status-{{ $order->payment_status }}">{{ str($order->payment_status)->replace('_', ' ')->title() }}</span></td>
                        <td><span class="order-status-badge order-status-{{ $order->fulfillment_status }}">{{ str($order->fulfillment_status)->replace('_', ' ')->title() }}</span></td>
                        <td>{{ $order->creator?->name ?? '—' }}</td>
                        <td>
                            <div class="order-row-actions">
                                <a class="button button-secondary" href="{{ route('restaurant.orders.show', $order) }}">View</a>
                                <a class="button button-secondary" href="{{ route('restaurant.orders.receipt', $order) }}" target="_blank" rel="noopener">Receipt</a>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $kind === 'billing' ? 9 : 13 }}"><div class="empty-state"><h3>No matching orders</h3><p>Try changing the search or filters. Completed POS orders will appear here.</p></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($orders->hasPages())
    <div class="page-tools pagination-tools">{{ $orders->links() }}</div>
@endif
