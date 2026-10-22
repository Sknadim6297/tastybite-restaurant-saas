@extends('layouts.restaurant-admin')

@section('title', 'Vendor Overview')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PURCHASING</span><h1>Vendor overview</h1><p>Supplier accounts and payable activity for {{ $restaurant->name }}.</p></div>
        <div class="toolbar-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.index') }}">All vendors</a><a class="button button-primary" href="{{ route('restaurant.vendors.create') }}">Add vendor</a></div>
    </div>

    <section class="summary-row report-summary" aria-label="Vendor summary">
        <article class="summary-tile"><span>Total vendors</span><strong>{{ number_format($totalVendors) }}</strong></article>
        <article class="summary-tile"><span>Active vendors</span><strong>{{ number_format($activeVendors) }}</strong></article>
        <article class="summary-tile"><span>Posted purchases</span><strong>INR {{ number_format((float) $totalPurchases, 2) }}</strong></article>
        <article class="summary-tile"><span>Outstanding payables</span><strong>INR {{ $outstandingPayables }}</strong></article>
        <article class="summary-tile"><span>Payments this month</span><strong>INR {{ $paymentsThisMonth }}</strong></article>
        <article class="summary-tile"><span>Overdue vendor bills</span><strong>INR {{ $overdueAmount }}</strong></article>
    </section>

    <div class="report-grid">
        <section>
            <div class="section-heading"><div><h2>Recent purchase orders</h2></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>PO</th><th>Vendor</th><th>Order date</th><th>Status</th><th>Total</th></tr></thead><tbody>
                @forelse($recentOrders as $order)
                    <tr><td>{{ $order->po_number ?? 'Draft #'.$order->id }}</td><td>{{ $order->vendor?->name ?? 'Vendor unavailable' }}</td><td>{{ $order->order_date?->format('M j, Y') ?? '—' }}</td><td>{{ \Illuminate\Support\Str::headline($order->status) }}</td><td>INR {{ number_format((float) $order->grand_total, 2) }}</td></tr>
                @empty<tr><td colspan="5"><div class="empty-state"><h3>No purchase orders yet</h3><p>Confirmed orders will appear here.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section>
            <div class="section-heading"><div><h2>Recent vendor payments</h2></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Reference</th><th>Vendor</th><th>Date</th><th>Amount</th><th>Type</th></tr></thead><tbody>
                @forelse($recentPayments as $payment)
                    <tr><td>{{ $payment->payment_reference ?? 'Payment #'.$payment->id }}</td><td>{{ $payment->vendor?->name ?? 'Vendor unavailable' }}</td><td>{{ $payment->payment_date?->format('M j, Y') ?? '—' }}</td><td>INR {{ number_format((float) $payment->amount, 2) }}</td><td>{{ \Illuminate\Support\Str::headline($payment->payment_type) }}</td></tr>
                @empty<tr><td colspan="5"><div class="empty-state"><h3>No vendor payments yet</h3><p>Recorded payments will appear here.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <div class="report-grid">
        <section>
            <div class="section-heading"><div><h2>Top vendors by posted purchases</h2></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Vendor</th><th>Purchase value</th><th>Outstanding</th></tr></thead><tbody>
                @forelse($topVendors as $row)
                    <tr><td><a class="table-primary-link" href="{{ route('restaurant.vendors.show', $row['vendor']) }}">{{ $row['vendor']->name }}</a></td><td>INR {{ number_format((float) $row['purchase_total'], 2) }}</td><td>INR {{ $money::formatCents((int) $row['outstanding']) }}</td></tr>
                @empty<tr><td colspan="3"><div class="empty-state"><h3>No posted purchases yet</h3><p>Vendor totals appear after bills are posted.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section>
            <div class="section-heading"><div><h2>Outstanding balances</h2></div></div>
            <div class="table-shell"><table class="data-table"><thead><tr><th>Vendor</th><th>Balance</th></tr></thead><tbody>
                @forelse($outstandingVendors as $row)
                    <tr><td><a class="table-primary-link" href="{{ route('restaurant.vendors.show', $row['vendor']) }}">{{ $row['vendor']->name }}</a></td><td>INR {{ $money::formatCents((int) $row['balance']) }}</td></tr>
                @empty<tr><td colspan="2"><div class="empty-state"><h3>No outstanding balances</h3><p>Posted payables will appear here.</p></div></td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <div class="section-heading"><div><h2>Recent goods receipts</h2></div></div>
    <div class="table-shell"><table class="data-table"><thead><tr><th>GRN</th><th>Purchase order</th><th>Vendor</th><th>Received</th><th>Received by</th></tr></thead><tbody>
        @forelse($recentReceipts as $receipt)
            <tr><td>{{ $receipt->grn_number ?? 'Receipt #'.$receipt->id }}</td><td>{{ $receipt->purchaseOrder?->po_number ?? '—' }}</td><td>{{ $receipt->vendor?->name ?? 'Vendor unavailable' }}</td><td>{{ $receipt->received_date?->format('M j, Y') ?? '—' }}</td><td>{{ $receipt->receiver?->name ?? '—' }}</td></tr>
        @empty<tr><td colspan="5"><div class="empty-state"><h3>No goods received yet</h3><p>Receipts will appear when deliveries are recorded.</p></div></td></tr>@endforelse
    </tbody></table></div>
@endsection