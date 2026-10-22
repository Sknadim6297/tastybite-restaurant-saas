<section class="panel-card invoice-receipt-card">
    <div class="invoice-receipt-heading">
        <div><span class="eyebrow">{{ $platformSettings['platform_name'] ?? 'TastyBite' }}</span><h2>Non-tax payment receipt</h2><p>{{ $invoice->invoice_number }}</p></div>
        <span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($invoice->payment->status) }}">{{ \App\Support\StatusPresenter::label($invoice->payment->status) }}</span>
    </div>
    <div class="detail-grid">
        <div class="detail-item"><span>Restaurant</span><strong>{{ $invoice->payment->restaurant->name }}</strong></div>
        <div class="detail-item"><span>Owner</span><strong>{{ $invoice->payment->restaurant->owners->first()?->name ?? 'No owner listed' }}</strong></div>
        <div class="detail-item"><span>Subscription plan</span><strong>{{ $invoice->payment->plan_name }} ({{ $invoice->payment->plan_code }})</strong></div>
        <div class="detail-item"><span>Billing period</span><strong>@if($invoice->subscription){{ $invoice->subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }} – {{ $invoice->subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}@else Not available @endif</strong></div>
        <div class="detail-item"><span>Payment reference</span><strong>{{ $invoice->payment->transaction_reference ?: 'Not provided' }}</strong></div>
        <div class="detail-item"><span>Payment method</span><strong>{{ ucfirst(str_replace('_', ' ', $invoice->payment->payment_method)) }}</strong></div>
        <div class="detail-item"><span>Payment date</span><strong>{{ $invoice->payment->paid_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</strong></div>
        <div class="detail-item"><span>Issued by</span><strong>{{ $invoice->issuer?->name ?? 'Former administrator' }}</strong></div>
    </div>
    <div class="table-shell invoice-totals-table">
        <table class="data-table"><tbody>
            <tr><th>Subtotal</th><td>{{ $invoice->payment->currency }} {{ $invoice->subtotal }}</td></tr>
            <tr><th>Tax</th><td>{{ $invoice->payment->currency }} {{ $invoice->tax_amount }} <span class="meta-label">Not configured</span></td></tr>
            <tr><th>Total received</th><td><strong>{{ $invoice->payment->currency }} {{ $invoice->total }}</strong></td></tr>
        </tbody></table>
    </div>
    <p class="invoice-disclaimer">Non-tax payment receipt only. This document does not claim legal tax-invoice status, include an unconfigured tax charge, or verify a refund transfer.</p>
</section>
