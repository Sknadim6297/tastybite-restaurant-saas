@extends('layouts.restaurant-admin')

@section('title', $vendor->name.' · Vendor')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">{{ $vendor->vendor_code }}</span><h1>{{ $vendor->name }}</h1><p>{{ $vendor->business_name ?: 'Vendor profile' }} · {{ $vendor->category ?: 'Uncategorized' }}</p></div>
        <div class="toolbar-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.index') }}">All vendors</a><a class="button button-primary" href="{{ route('restaurant.vendors.edit', $vendor) }}">Edit vendor</a></div>
    </div>
    <nav class="report-tabs vendor-detail-tabs" aria-label="Vendor details">
        <a class="is-active" href="#vendor-overview">Overview</a>
        <a href="#vendor-contact">Contact &amp; Business Details</a>
        <a href="#vendor-orders">Purchase Orders</a>
        <a href="#vendor-receipts">Goods Receipts</a>
        <a href="#vendor-bills">Bills</a>
        <a href="#vendor-payments">Payments</a>
        <a href="#vendor-history">Transaction History</a>
    </nav>

    <section id="vendor-overview" class="summary-row report-summary">
        <article class="summary-tile"><span>Total purchases</span><strong>INR {{ number_format((float) $totalPurchases, 2) }}</strong></article>
        <article class="summary-tile"><span>Total paid</span><strong>INR {{ $totalPaid }}</strong></article>
        <article class="summary-tile"><span>Outstanding</span><strong>INR {{ $money::formatCents((int) $balance) }}</strong></article>
        <article class="summary-tile"><span>Overdue</span><strong>INR {{ $money::formatCents((int) $overdueAmount) }}</strong></article>
        <article class="summary-tile"><span>Last purchase</span><strong>{{ $lastPurchaseDate ? \Illuminate\Support\Carbon::parse($lastPurchaseDate)->format('M j, Y') : '—' }}</strong></article>
        <article class="summary-tile"><span>Last payment</span><strong>{{ $lastPaymentDate ? \Illuminate\Support\Carbon::parse($lastPaymentDate)->format('M j, Y') : '—' }}</strong></article>
    </section>

    <section id="vendor-contact" class="panel-card">
        <div class="section-heading"><div><h2>Contact &amp; Business Details</h2></div><span class="status-pill status-{{ $vendor->status === 'active' ? 'active' : 'disabled' }}">{{ ucfirst($vendor->status) }}</span></div>
        <div class="detail-grid">
            <div class="detail-item"><span>Vendor code</span><strong>{{ $vendor->vendor_code }}</strong></div>
            <div class="detail-item"><span>Contact person</span><strong>{{ $vendor->contact_person ?: '—' }}</strong></div>
            <div class="detail-item"><span>Mobile</span><strong>{{ $vendor->mobile }}</strong></div>
            <div class="detail-item"><span>Alternate mobile</span><strong>{{ $vendor->alternate_mobile ?: '—' }}</strong></div>
            <div class="detail-item"><span>Email</span><strong>{{ $vendor->email ?: '—' }}</strong></div>
            <div class="detail-item"><span>Category</span><strong>{{ $vendor->category ?: '—' }}</strong></div>
            <div class="detail-item"><span>Business name</span><strong>{{ $vendor->business_name ?: '—' }}</strong></div>
            <div class="detail-item"><span>GSTIN</span><strong>{{ $vendor->gstin ?: '—' }}</strong></div>
            <div class="detail-item"><span>PAN</span><strong>{{ $vendor->pan_number ?: '—' }}</strong></div>
            <div class="detail-item"><span>Registration number</span><strong>{{ $vendor->registration_number ?: '—' }}</strong></div>
            <div class="detail-item"><span>Payment terms</span><strong>{{ $vendor->payment_terms_days }} days</strong></div>
            <div class="detail-item"><span>Credit limit</span><strong>{{ $vendor->credit_limit ? 'INR '.number_format((float) $vendor->credit_limit, 2) : '—' }}</strong></div>
            <div class="detail-item"><span>Address</span><strong>{{ collect([$vendor->address_line_1, $vendor->address_line_2, $vendor->city, $vendor->state, $vendor->postal_code, $vendor->country])->filter()->join(', ') ?: '—' }}</strong></div>
            <div class="detail-item"><span>Account holder</span><strong>{{ $vendor->account_holder_name ?: '—' }}</strong></div>
            <div class="detail-item"><span>Bank</span><strong>{{ $vendor->bank_name ?: '—' }} · {{ $vendor->bank_branch ?: '—' }}</strong></div>
            <div class="detail-item"><span>Account number</span><strong>{{ $maskedAccountNumber }}</strong></div>
            <div class="detail-item"><span>IFSC</span><strong>{{ $vendor->ifsc_code ?: '—' }}</strong></div>
            <div class="detail-item"><span>UPI ID</span><strong>{{ $vendor->upi_id ?: '—' }}</strong></div>
            <div class="detail-item"><span>Created by</span><strong>{{ $vendor->creator?->name ?? '—' }}</strong></div>
            <div class="detail-item"><span>Created</span><strong>{{ $vendor->created_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</strong></div>
            <div class="detail-item"><span>Updated</span><strong>{{ $vendor->updated_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</strong></div>
            @if($vendor->notes)<div class="detail-item detail-item-wide"><span>Notes</span><strong style="white-space:pre-wrap">{{ $vendor->notes }}</strong></div>@endif
        </div>
    </section>

    <section id="vendor-orders">
        <div class="section-heading"><div><h2>Purchase Orders</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>PO</th><th>Order date</th><th>Expected</th><th>Status</th><th>Total</th></tr></thead><tbody>
            @forelse($orders as $order)<tr><td>{{ $order->po_number ?? 'Draft #'.$order->id }}</td><td>{{ $order->order_date?->format('M j, Y') }}</td><td>{{ $order->expected_delivery_date?->format('M j, Y') ?? '—' }}</td><td>{{ \Illuminate\Support\Str::headline($order->status) }}</td><td>INR {{ number_format((float) $order->grand_total, 2) }}</td></tr>
            @empty<tr><td colspan="5"><div class="empty-state"><h3>No purchase orders</h3><p>Purchase orders for this vendor will appear here.</p></div></td></tr>@endforelse
        </tbody></table></div>@if($orders->hasPages())<div class="page-tools pagination-tools">{{ $orders->links() }}</div>@endif
    </section>

    <section id="vendor-receipts">
        <div class="section-heading"><div><h2>Goods Receipts</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>GRN</th><th>Purchase order</th><th>Received date</th><th>Items</th></tr></thead><tbody>
            @forelse($receipts as $receipt)<tr><td>{{ $receipt->grn_number ?? 'Receipt #'.$receipt->id }}</td><td>{{ $receipt->purchaseOrder?->po_number ?? '—' }}</td><td>{{ $receipt->received_date?->format('M j, Y') }}</td><td>{{ $receipt->items()->count() }}</td></tr>
            @empty<tr><td colspan="4"><div class="empty-state"><h3>No goods receipts</h3><p>Received deliveries will appear here.</p></div></td></tr>@endforelse
        </tbody></table></div>@if($receipts->hasPages())<div class="page-tools pagination-tools">{{ $receipts->links() }}</div>@endif
    </section>

    <section id="vendor-bills">
        <div class="section-heading"><div><h2>Bills</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Vendor invoice</th><th>Invoice date</th><th>Due date</th><th>Total</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>
            @forelse($bills as $bill)@php($billOutstanding = $accounting->billOutstandingCents($bill))<tr><td>{{ $bill->vendor_invoice_number ?: ($bill->bill_number ?? 'Bill #'.$bill->id) }}</td><td>{{ $bill->invoice_date?->format('M j, Y') }}</td><td>{{ $bill->due_date?->format('M j, Y') ?? '—' }}</td><td>INR {{ number_format((float) $bill->total_amount, 2) }}</td><td>INR {{ $money::formatCents($billOutstanding) }}</td><td>{{ \Illuminate\Support\Str::headline($accounting->billStatus($bill)) }}</td></tr>
            @empty<tr><td colspan="6"><div class="empty-state"><h3>No vendor bills</h3><p>Vendor bills will appear here.</p></div></td></tr>@endforelse
        </tbody></table></div>@if($bills->hasPages())<div class="page-tools pagination-tools">{{ $bills->links() }}</div>@endif
    </section>

    <section id="vendor-payments">
        <div class="section-heading"><div><h2>Payments</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Reference</th><th>Date</th><th>Method</th><th>Transaction reference</th><th>Amount</th><th>Status</th></tr></thead><tbody>
            @forelse($payments as $payment)<tr><td>{{ $payment->payment_reference ?? 'Payment #'.$payment->id }}</td><td>{{ $payment->payment_date?->format('M j, Y') }}</td><td>{{ \Illuminate\Support\Str::headline($payment->payment_method) }}</td><td>{{ $payment->transaction_reference ?: '—' }}</td><td>INR {{ number_format((float) $payment->amount, 2) }}</td><td>{{ \Illuminate\Support\Str::headline($payment->payment_type) }}</td></tr>
            @empty<tr><td colspan="6"><div class="empty-state"><h3>No vendor payments</h3><p>Payments recorded for this vendor will appear here.</p></div></td></tr>@endforelse
        </tbody></table></div>@if($payments->hasPages())<div class="page-tools pagination-tools">{{ $payments->links() }}</div>@endif
    </section>

    <section id="vendor-history">
        <div class="section-heading"><div><h2>Transaction History</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>Adjustment</th><th>Date</th><th>Type</th><th>Description</th><th>Amount</th></tr></thead><tbody>
            @forelse($vendor->adjustments()->latest('entry_date')->limit(20)->get() as $entry)<tr><td>{{ $entry->adjustment_reference }}</td><td>{{ $entry->entry_date?->format('M j, Y') }}</td><td>{{ \Illuminate\Support\Str::headline($entry->entry_type) }}</td><td>{{ $entry->description }}</td><td>INR {{ number_format((float) $entry->amount, 2) }}</td></tr>
            @empty<tr><td colspan="5"><div class="empty-state"><h3>No opening or adjustment entries</h3><p>Vendor payable adjustments will appear here.</p></div></td></tr>@endforelse
        </tbody></table></div>
    </section>
@endsection