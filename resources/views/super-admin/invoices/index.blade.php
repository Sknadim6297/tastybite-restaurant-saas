@extends('layouts.super-admin')

@section('title', 'Invoices & Billing')
@section('section', 'Invoices & Billing')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PAYMENT DOCUMENTS</span><h1>Invoices &amp; billing</h1><p>Receipts are generated only when a manual subscription payment is approved. Demo records are clearly marked.</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.invoices.export', request()->query()) }}">Export CSV</a>
    </div>

    <div class="page-tools invoice-filter-tools">
        <form method="GET" action="{{ route('super-admin.invoices.index') }}" class="toolbar-actions">
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Invoice, restaurant, owner, or reference">
            <select class="toolbar-search" name="restaurant_id" aria-label="Filter by restaurant"><option value="">All restaurants</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select>
            <select class="toolbar-search" name="status" aria-label="Filter by payment status"><option value="">All payment statuses</option>@foreach(['pending', 'approved', 'rejected', 'refunded'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
            <select class="toolbar-search" name="scope" aria-label="Filter demo records"><option value="all" @selected(($filters['scope'] ?? 'all') === 'all')>All records</option><option value="real" @selected(($filters['scope'] ?? '') === 'real')>Real records</option><option value="demo" @selected(($filters['scope'] ?? '') === 'demo')>Demo records</option></select>
            <input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="Issue date from">
            <input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="Issue date to">
            <button class="button button-secondary" type="submit">Apply filters</button>
            <a class="button button-secondary" href="{{ route('super-admin.invoices.index') }}">Reset</a>
        </form>
    </div>

    <div class="table-shell">
        <table class="data-table invoice-table">
            <thead><tr><th>Receipt</th><th>Restaurant / Owner</th><th>Plan / Billing Period</th><th>Reference</th><th>Total</th><th>Payment Status</th><th>Issue Date</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($invoices as $invoice)
                    @php($payment = $invoice->payment)
                    <tr>
                        <td><strong>{{ $invoice->invoice_number }}</strong><br><span class="meta-label">Non-tax receipt @if($payment->isDemo()) · Demo only @endif</span></td>
                        <td><strong>{{ $payment->restaurant->name }}</strong><br><span class="meta-label">{{ $payment->restaurant->owners->first()?->name ?? 'No owner listed' }}</span></td>
                        <td>{{ $payment->plan_name }}<br><span class="meta-label">@if($payment->subscription){{ $payment->subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }} – {{ $payment->subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}@else Period unavailable @endif</span></td>
                        <td>{{ $payment->transaction_reference ?: '—' }}</td>
                        <td>{{ $payment->currency }} {{ $invoice->total }}<br><span class="meta-label">Net {{ $invoice->netRetainedAmount() }}</span></td>
                        <td><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td>
                        <td>{{ $invoice->issue_date->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.invoices.show', $invoice) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><h3>No receipts found</h3><p>Approved manual payments will automatically create a linked receipt.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="page-tools pagination-tools">{{ $invoices->links() }}</div>
@endsection
