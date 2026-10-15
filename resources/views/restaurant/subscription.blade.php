@extends('layouts.restaurant-admin')

@section('title', 'Subscription & Billing')
@section('section', 'Subscription & Billing')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">ACCOUNT ACCESS</span><h1>Subscription &amp; billing</h1><p>Plans, payment verification, and receipts for {{ $restaurant->name }}.</p></div>
        @if($subscriptionStatus)<span class="status-pill status-{{ str_replace('_', '-', $subscriptionStatus) }}">{{ str_replace('_', ' ', ucfirst($subscriptionStatus)) }}</span>@endif
    </div>

    @if(session('status'))
        <div class="notice-card" role="status">{{ session('status') }}</div>
    @endif

    @forelse($subscriptions as $restaurantSubscription)
        @php($summary = $paymentSummaries[$restaurantSubscription->id])
        <section class="panel-card subscription-billing-card">
            <div class="section-heading">
                <div><h2>{{ $restaurantSubscription->plan_name }}</h2><p>{{ $restaurantSubscription->formatted_id }} · {{ $restaurantSubscription->plan_code }}</p></div>
                <span class="status-pill status-{{ str_replace('_', '-', $restaurantSubscription->effectiveStatus()) }}">{{ str_replace('_', ' ', ucfirst($restaurantSubscription->effectiveStatus())) }}</span>
            </div>
            <div class="detail-grid">
                <div class="detail-item"><span>Plan price</span><strong>{{ $restaurantSubscription->currency }} {{ number_format((float) $restaurantSubscription->price, 2) }}</strong></div>
                <div class="detail-item"><span>Billing cycle</span><strong>{{ ucfirst($restaurantSubscription->billing_period) }}</strong></div>
                <div class="detail-item"><span>Payment status</span><strong>{{ $summary['payment_status'] }}</strong></div>
                <div class="detail-item"><span>Already approved</span><strong>{{ $restaurantSubscription->currency }} {{ $summary['already_paid'] }}</strong></div>
                <div class="detail-item"><span>Outstanding balance</span><strong>{{ $restaurantSubscription->currency }} {{ $summary['outstanding'] }}</strong></div>
                @if(in_array($restaurantSubscription->status, ['active', 'trial', 'scheduled'], true))
                    <div class="detail-item"><span>Start date</span><strong>{{ $restaurantSubscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
                    <div class="detail-item"><span>Expiry date</span><strong>{{ $restaurantSubscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</strong></div>
                @else
                    <div class="detail-item"><span>Start date</span><strong>Available after payment approval</strong></div>
                    <div class="detail-item"><span>Expiry date</span><strong>Available after payment approval</strong></div>
                @endif
                @if($restaurantSubscription->renewal_of_id)
                    <div class="detail-item"><span>Renewal of</span><strong>SUB{{ sprintf('%06d', $restaurantSubscription->renewal_of_id) }}</strong></div>
                @endif
            </div>

            @if($paymentSettings['manual_enabled'] && in_array($restaurantSubscription->status, ['pending_payment', 'pending_approval'], true) && (float) $summary['available_amount'] > 0)
                <div class="section-heading"><div><h3>Submit payment for verification</h3><p>Payment is not confirmed until a Super Admin verifies it.</p></div></div>
                @if(filled($paymentSettings['instructions']))
                    <div class="notice-card"><strong>Payment instructions</strong><p>{{ $paymentSettings['instructions'] }}</p></div>
                @endif
                <form method="POST" enctype="multipart/form-data" action="{{ route('restaurant.subscription.payments.store', $restaurantSubscription) }}" class="form-stack" data-loading-form>
                    @csrf
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="amount-{{ $restaurantSubscription->id }}">Amount (up to {{ $restaurantSubscription->currency }} {{ $summary['available_amount'] }})</label>
                            <input id="amount-{{ $restaurantSubscription->id }}" name="amount" type="number" min="0.01" max="{{ $summary['available_amount'] }}" step="0.01" value="{{ old('amount') }}" required>
                            @error('amount')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-field">
                            <label for="payment-method-{{ $restaurantSubscription->id }}">Payment method</label>
                            <select id="payment-method-{{ $restaurantSubscription->id }}" name="payment_method" required>
                                <option value="">Select a method</option>
                                @foreach($paymentSettings['allowed_methods'] as $method)
                                    <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-field">
                            <label for="reference-{{ $restaurantSubscription->id }}">Transaction reference</label>
                            <input id="reference-{{ $restaurantSubscription->id }}" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="255">
                            @error('transaction_reference')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-field">
                            <label for="proof-{{ $restaurantSubscription->id }}">Payment proof{{ $paymentSettings['proof_required'] ? ' (required)' : ' (optional)' }}</label>
                            <input id="proof-{{ $restaurantSubscription->id }}" name="proof" type="file" accept=".jpg,.jpeg,.png,.pdf" @required($paymentSettings['proof_required'])>
                            <span class="field-hint">JPG, PNG, or PDF up to 5 MB.</span>
                            @error('proof')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-field form-field-full">
                            <label for="payment-notes-{{ $restaurantSubscription->id }}">Notes (optional)</label>
                            <textarea id="payment-notes-{{ $restaurantSubscription->id }}" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                            @error('notes')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    @error('subscription')<span class="field-error">{{ $message }}</span>@enderror
                    <div class="form-actions"><button class="button button-primary" type="submit">Submit for verification</button></div>
                </form>
            @elseif($summary['payment_status'] === 'Fully Paid')
                <div class="notice-card"><strong>Fully Paid</strong><p>The approved payment balance covers this subscription.</p></div>
            @endif

            <div class="section-heading"><div><h3>Payment history</h3><p>Submission and approval records for this subscription.</p></div></div>
            <div class="table-shell">
                <table class="data-table">
                    <thead><tr><th>Payment</th><th>Amount</th><th>Method / Reference</th><th>Status</th><th>Submitted</th><th>Rejection reason</th><th>Receipt</th></tr></thead>
                    <tbody>
                        @forelse($restaurantSubscription->payments as $payment)
                            <tr>
                                <td>{{ $payment->formatted_id }}</td>
                                <td>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                                <td>{{ $payment->payment_method_label ?? '—' }}<br><span class="meta-label">{{ $payment->transaction_reference ?: '—' }}</span></td>
                                <td><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td>
                                <td>{{ $payment->paid_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</td>
                                <td>{{ $payment->rejection_reason ?: '—' }}</td>
                                <td>@if($payment->invoice)<a class="button button-secondary" href="{{ route('restaurant.subscription.invoices.pdf', $payment->invoice) }}">Download receipt</a>@else—@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty-state"><h3>No payments submitted</h3><p>Submitted payment records will appear here.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <section class="notice-card"><strong>No subscription has been assigned.</strong><p>Contact the TastyBite platform administrator for plan and billing information.</p></section>
    @endforelse

    <div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.dashboard') }}">Back to dashboard</a><a class="button button-secondary" href="{{ route('restaurant.profile') }}">Account profile</a></div>
@endsection
