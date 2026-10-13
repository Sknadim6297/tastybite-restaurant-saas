@extends('layouts.super-admin')

@section('title', $invoice->invoice_number)
@section('section', 'Invoices & Billing')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">NON-TAX PAYMENT RECEIPT @if($invoice->payment->isDemo()) · DEMO ONLY — NOT ACTUAL MONEY @endif</span><h1>{{ $invoice->invoice_number }}</h1><p>Issued {{ $invoice->issue_date->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</p></div>
        <div class="toolbar-actions"><a class="button button-secondary" href="{{ route('super-admin.invoices.print', $invoice) }}">Print</a><a class="button button-primary" href="{{ route('super-admin.invoices.pdf', $invoice) }}">Download PDF</a></div>
    </div>

    @include('super-admin.invoices._receipt-details', ['invoice' => $invoice])
    <section class="panel-card invoice-refunds">
        <div class="section-heading"><div><h2>Refund records</h2><p>Manual records do not confirm that funds were returned by an external provider.</p></div></div>
        <div class="invoice-refund-summary">
            <div><span>Original total</span><strong>{{ $invoice->payment->currency }} {{ $invoice->total }}</strong></div>
            <div><span>Refunds recorded</span><strong>{{ $invoice->payment->currency }} {{ $invoice->recordedRefundAmount() }}</strong></div>
            <div><span>Net retained</span><strong>{{ $invoice->payment->currency }} {{ $invoice->netRetainedAmount() }}</strong></div>
        </div>
        <div class="table-shell">
            <table class="data-table"><thead><tr><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Reason</th><th>Administrator</th></tr></thead><tbody>
                @forelse($invoice->payment->refunds as $refund)
                    <tr><td>{{ $invoice->payment->currency }} {{ $refund->amount }}</td><td>{{ ucfirst(str_replace('_', ' ', $refund->method)) }}</td><td>{{ $refund->reference ?: '—' }}</td><td>{{ $refund->refunded_at->timezone(config('app.timezone'))->format('M j, Y') }}</td><td>{{ $refund->reason }}</td><td>{{ $refund->administrator?->name ?? 'Unavailable' }}</td></tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><h3>No refund records</h3><p>Refunds, when recorded, will appear here.</p></div></td></tr>
                @endforelse
            </tbody></table>
        </div>
    </section>
    <p class="invoice-disclaimer">This is a non-tax payment receipt. Tax registration details and applicable tax configuration are not currently set up. No tax invoice status or tax charge is represented.</p>
@endsection
