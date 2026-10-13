@extends('layouts.super-admin')

@section('title', 'Payment #'.$payment->id)
@section('section', 'Payments')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PAYMENT RECORD #{{ $payment->id }} @if($payment->isDemo()) · DEMO ONLY — NOT ACTUAL MONEY @endif</span><h1>{{ $payment->restaurant->name }}</h1><p>{{ $payment->plan_name }} · {{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</p></div><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></div>

    <section class="panel-card"><div class="detail-grid">
        <div class="detail-item"><span>Payment ID</span><strong>#{{ $payment->id }}</strong></div>
        <div class="detail-item"><span>Restaurant / Owner</span><strong>{{ $payment->restaurant->name }} · {{ $payment->restaurant->owners->first()?->name ?? 'No owner listed' }} · {{ ucfirst($payment->restaurant->status) }}</strong></div>
        <div class="detail-item"><span>Restaurant contact</span><strong>{{ $payment->restaurant->email ?: 'No email' }} · {{ $payment->restaurant->phone ?: 'No phone' }}</strong></div>
        <div class="detail-item"><span>Plan</span><strong>{{ $payment->plan_name }} ({{ $payment->plan_code }})</strong></div>
        <div class="detail-item"><span>Amount / Method</span><strong>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }} · {{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</strong></div>
        <div class="detail-item"><span>Transaction reference</span><strong>{{ $payment->transaction_reference ?: 'Not provided' }}</strong></div>
        <div class="detail-item"><span>Payment date</span><strong>{{ $payment->paid_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</strong></div>
        <div class="detail-item"><span>Recorded by</span><strong>{{ $payment->creator?->name ?? 'Unavailable' }}</strong></div>
        <div class="detail-item"><span>Approval / Rejection</span><strong>@if($payment->approver)Approved by {{ $payment->approver->name }} · {{ $payment->approved_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}@elseif($payment->rejector)Rejected by {{ $payment->rejector->name }} · {{ $payment->rejected_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}@else Pending review @endif</strong></div>
        @if($payment->rejection_reason)<div class="detail-item detail-item-wide"><span>Rejection reason</span><strong>{{ $payment->rejection_reason }}</strong></div>@endif
        @if($payment->notes)<div class="detail-item detail-item-wide"><span>Notes</span><strong>{{ $payment->notes }}</strong></div>@endif
        @if($payment->subscription)<div class="detail-item"><span>Linked subscription</span><strong><a href="{{ route('super-admin.subscriptions.show', $payment->subscription) }}">Subscription #{{ $payment->subscription->id }}</a></strong></div>@endif
        @if($payment->invoice)<div class="detail-item"><span>Payment receipt</span><strong><a href="{{ route('super-admin.invoices.show', $payment->invoice) }}">{{ $payment->invoice->invoice_number }} · View receipt</a></strong></div>@endif
        @if($payment->proof_path)<div class="detail-item"><span>Payment proof</span><strong><a href="{{ route('super-admin.payments.proof', $payment) }}">Download protected attachment</a></strong></div>@endif
    </div></section>

    @if($payment->status === 'pending')
        <div class="section-heading"><div><h2>Review payment</h2><p>Approval creates or renews the restaurant subscription atomically.</p></div></div>
        <section class="payment-actions panel-card">
            <form method="POST" action="{{ route('super-admin.payments.approve', $payment) }}" data-loading-form data-confirm="Approve this payment and create or renew its subscription period?" data-confirm-title="Approve payment?" data-confirm-action="Approve">
                @csrf<button class="button button-primary" type="submit">Approve and activate subscription</button>
            </form>
            <form method="POST" action="{{ route('super-admin.payments.reject', $payment) }}" class="form-stack" data-loading-form data-confirm="Reject this pending payment? This will not change the restaurant subscription." data-confirm-title="Reject payment?" data-confirm-action="Reject">
                @csrf<div class="form-field"><label for="reason">Rejection reason</label><textarea id="reason" name="reason" rows="3" required minlength="3" maxlength="2000">{{ old('reason') }}</textarea>@error('reason')<span class="field-error">{{ $message }}</span>@enderror</div>
                <button class="button button-danger" type="submit">Reject payment</button>
            </form>
        </section>
    @endif

    @if(in_array($payment->status, ['approved', 'refunded'], true))
        <div class="section-heading"><div><h2>Refunds</h2><p>Remaining refundable amount: {{ $payment->currency }} {{ number_format((float) $payment->remainingRefundableAmount(), 2) }}. Recording does not confirm funds were actually returned.</p></div></div>
        @if((float) $payment->remainingRefundableAmount() > 0)
            <form method="POST" action="{{ route('super-admin.payments.refunds.store', $payment) }}" class="panel-card form-stack" data-loading-form data-confirm="Record this manual refund? This records the refund details but does not confirm money was returned." data-confirm-title="Record manual refund?" data-confirm-action="Record refund">
                @csrf<div class="form-grid">
                    <div class="form-field"><label for="refund_amount">Refund amount</label><input id="refund_amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $payment->remainingRefundableAmount() }}" value="{{ old('amount') }}" required>@error('amount')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field"><label for="refund_method">Refund method</label><select id="refund_method" name="method" required>@foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','upi'=>'UPI','other'=>'Other'] as $value=>$label)<option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-field"><label for="refund_reference">Refund reference</label><input id="refund_reference" name="reference" maxlength="255" value="{{ old('reference') }}">@error('reference')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field"><label for="refunded_at">Refund date</label><input id="refunded_at" type="datetime-local" name="refunded_at" value="{{ old('refunded_at', now(config('app.timezone'))->format('Y-m-d\TH:i')) }}" required></div>
                    <div class="form-field form-field-full"><label for="refund_reason">Reason</label><textarea id="refund_reason" name="reason" rows="3" required minlength="3" maxlength="2000">{{ old('reason') }}</textarea>@error('reason')<span class="field-error">{{ $message }}</span>@enderror</div>
                </div><div class="form-actions"><button class="button button-secondary" type="submit">Record manual refund</button></div>
            </form>
        @endif
        <div class="table-shell"><table class="data-table"><thead><tr><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Reason</th><th>Recorded by</th></tr></thead><tbody>
            @forelse($payment->refunds as $refund)<tr><td>{{ $payment->currency }} {{ number_format((float) $refund->amount, 2) }}</td><td>{{ ucfirst(str_replace('_', ' ', $refund->method)) }}</td><td>{{ $refund->reference ?: '—' }}</td><td>{{ $refund->refunded_at->timezone(config('app.timezone'))->format('M j, Y') }}</td><td>{{ $refund->reason }}</td><td>{{ $refund->administrator?->name ?? 'Unavailable' }}</td></tr>
            @empty<tr><td colspan="6"><div class="empty-state"><h3>No refunds recorded</h3><p>Manual refunds will be listed here.</p></div></td></tr>@endforelse
        </tbody></table></div>
    @endif

    <div class="section-heading"><div><h2>Audit history</h2><p>Financial actions are recorded with the acting administrator.</p></div></div>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Action</th><th>Administrator</th><th>Recorded at</th></tr></thead><tbody>
        @forelse($audit as $entry)<tr><td>{{ ucfirst(str_replace('_', ' ', $entry->action)) }}</td><td>{{ $entry->actor?->name ?? 'Unavailable' }}</td><td>{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</td></tr>
        @empty<tr><td colspan="3"><div class="empty-state"><h3>No audit entries</h3><p>Financial actions will be recorded here.</p></div></td></tr>@endforelse
    </tbody></table></div>
@endsection
