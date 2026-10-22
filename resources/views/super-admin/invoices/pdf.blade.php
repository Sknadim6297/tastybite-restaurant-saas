<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.5; }
        .brand { color: #101827; font-size: 20px; font-weight: bold; }
        h1 { margin: 22px 0 4px; color: #101827; font-size: 19px; }
        .muted { color: #64748b; }
        table { width: 100%; margin: 18px 0; border-collapse: collapse; }
        th, td { padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { width: 32%; color: #475569; }
        .amount { text-align: right; }
        .total { font-size: 13px; font-weight: bold; }
        .disclaimer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #64748b; }
    </style>
</head>
<body>
    <div class="brand">{{ $platformSettings['platform_name'] ?? 'TastyBite' }}</div>
    <h1>Non-tax payment receipt</h1>
    <div class="muted">{{ $invoice->invoice_number }} · Issued {{ $invoice->issue_date->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</div>
    <table>
        <tbody>
            <tr><th>Restaurant</th><td>{{ $invoice->payment->restaurant->name }}</td></tr>
            <tr><th>Owner</th><td>{{ $invoice->payment->restaurant->owners->first()?->name ?? 'No owner listed' }}</td></tr>
            <tr><th>Subscription plan</th><td>{{ $invoice->payment->plan_name }} ({{ $invoice->payment->plan_code }})</td></tr>
            <tr><th>Billing period</th><td>@if($invoice->subscription){{ $invoice->subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }} – {{ $invoice->subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}@else Not available @endif</td></tr>
            <tr><th>Payment reference</th><td>{{ $invoice->payment->transaction_reference ?: 'Not provided' }}</td></tr>
            <tr><th>Method / payment status</th><td>{{ \App\Support\StatusPresenter::label($invoice->payment->payment_method) }} / {{ \App\Support\StatusPresenter::label($invoice->payment->status) }}</td></tr>
            <tr><th>Subtotal</th><td class="amount">{{ $invoice->payment->currency }} {{ $invoice->subtotal }}</td></tr>
            <tr><th>Tax</th><td class="amount">{{ $invoice->payment->currency }} {{ $invoice->tax_amount }} (not configured)</td></tr>
            <tr><th>Total received</th><td class="amount total">{{ $invoice->payment->currency }} {{ $invoice->total }}</td></tr>
            <tr><th>Refunds recorded</th><td class="amount">{{ $invoice->payment->currency }} {{ $invoice->recordedRefundAmount() }}</td></tr>
            <tr><th>Net retained</th><td class="amount">{{ $invoice->payment->currency }} {{ $invoice->netRetainedAmount() }}</td></tr>
        </tbody>
    </table>
    <p class="disclaimer">This is a non-tax payment receipt. Tax registration details and applicable tax configuration are not currently set up. This document does not claim legal tax-invoice status or confirm that recorded refunds were externally returned.</p>
</body>
</html>
