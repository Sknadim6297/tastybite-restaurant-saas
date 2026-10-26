
@extends('layouts.restaurant-admin')

@section('title', 'POS Order History')
@section('section', 'POS Billing')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">Completed POS sales</span>
            <h1>Order history</h1>
            <p>Recipe-based stock is deducted when a sale is completed; cancelling posts compensating stock movements.</p>
        </div>

        <a class="button button-primary" href="{{ route('pos.index') }}">
            Back to POS
        </a>
    </div>

    @if (session('status'))
        <div class="notice-card" role="status">
            {{ session('status') }}
        </div>
    @endif

    <section class="panel-card">
        <div class="table-shell">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment Mode</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($orders as $order)
                        @php
                            $recordedPayments = $order->payments
                                ->where('status', 'recorded');

                            $paidTotal = (float) $recordedPayments->sum('amount');

                            $paymentMethod = $order->payment_method
                                ?: $recordedPayments->first()?->method;

                            $paymentStatus = $order->payment_status ?: 'unpaid';

                            $canPrintInvoice = $order->status === 'completed';
                        @endphp

                        <tr>
                            <td>
                                <strong>{{ $order->order_number }}</strong>
                                <small>{{ $order->items->count() }} line(s)</small>
                            </td>

                            <td>
                                {{ ($order->completed_at ?? $order->created_at)?->format('M j, Y H:i') }}
                            </td>

                            <td>
                                @foreach ($order->items as $item)
                                    {{ $item->item_name }} × {{ $item->quantity }}

                                    @if ($item->recipe_version)
                                        <small>(recipe v{{ $item->recipe_version }})</small>
                                    @endif

                                    @if (! $loop->last), @endif
                                @endforeach
                            </td>

                            <td>
                                <strong>
                                    ₹{{ number_format((float) $order->total, 2) }}
                                </strong>
                            </td>

                            <td>
                                <strong>
                                    {{ $paymentMethod ? strtoupper($paymentMethod) : 'N/A' }}
                                </strong>

                                @if ($paidTotal > 0)
                                    <small>
                                        Recorded: ₹{{ number_format($paidTotal, 2) }}
                                    </small>
                                @endif
                            </td>

                            <td>
                                <span class="status-badge {{ $paymentStatus === 'paid' ? 'status-badge-success' : 'status-badge-muted' }}">
                                    {{ str($paymentStatus)->replace('_', ' ')->title() }}
                                </span>
                            </td>

                            <td>
                                <span class="status-badge {{ $order->status === 'completed' ? 'status-badge-success' : 'status-badge-muted' }}">
                                    {{ ucfirst($order->status) }}
                                </span>
                            </td>

                            <td>
                                <div class="order-row-actions">
                                    @if ($canPrintInvoice)
                                        <a
                                            class="button button-secondary"
                                            href="{{ route('restaurant.orders.receipt', $order) }}"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            🖨 Print Invoice
                                        </a>
                                    @endif

                                    @if ($order->status === 'completed' && ! request()->attributes->has('restaurant_admin_access_restaurant'))
                                        <form
                                            class="pos-order-cancel-form"
                                            method="POST"
                                            action="{{ route('pos.orders.cancel', $order) }}"
                                            data-confirm="Cancel this completed sale? This will add auditable stock reversal movements."
                                        >
                                            @csrf

                                            <input
                                                name="reason"
                                                aria-label="Cancellation reason"
                                                placeholder="Required reason"
                                                minlength="5"
                                                maxlength="2000"
                                                required
                                            >

                                            <button
                                                class="text-button danger-text"
                                                type="submit"
                                            >
                                                Cancel and reverse
                                            </button>
                                        </form>
                                    @endif

                                    @if (! $canPrintInvoice)
                                        <span>—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <h3>No POS orders yet</h3>
                                    <p>Completed sales will be listed here.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="page-tools pagination-tools">
                {{ $orders->links() }}
            </div>
        @endif
    </section>
@endsection
