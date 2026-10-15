@extends('layouts.super-admin')

@section('title', $expiring ? 'Expiring Soon' : 'Restaurant Subscriptions')
@section('section', $expiring ? 'Expiring Soon' : 'Restaurant Subscriptions')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">SUBSCRIPTION OPERATIONS</span>
            <h1>{{ $expiring ? 'Expiring soon' : 'Restaurant subscriptions & billing' }}</h1>
            <p>{{ $expiring ? 'Active and trial periods ending within the next 30 days.' : 'Manage assignments, payment verification, billing history, refunds, and reports.' }}</p>
        </div>
        <a class="button button-primary" href="{{ route('super-admin.subscriptions.create') }}">Assign subscription</a>
    </div>

    @unless($expiring)
        <section class="summary-row subscription-overview" aria-label="Subscription and billing summary">
            <article class="summary-tile"><span>Total subscriptions</span><strong>{{ number_format($summary['total_subscriptions']) }}</strong></article>
            <article class="summary-tile"><span>Active subscriptions</span><strong>{{ number_format($summary['active_subscriptions']) }}</strong></article>
            <article class="summary-tile"><span>Pending payments</span><strong>{{ number_format($summary['pending_payments']) }}</strong></article>
            <article class="summary-tile"><span>Pending verification</span><strong>{{ number_format($summary['pending_approvals']) }}</strong></article>
            <article class="summary-tile"><span>Expired subscriptions</span><strong>{{ number_format($summary['expired_subscriptions']) }}</strong></article>
            <article class="summary-tile"><span>Net collected</span><strong>INR {{ $summary['collected'] }}</strong></article>
            <article class="summary-tile"><span>Pending balance</span><strong>INR {{ $summary['pending_amount'] }}</strong></article>
            <article class="summary-tile"><span>Recorded refunds</span><strong>INR {{ $summary['refunded'] }}</strong></article>
        </section>

        <nav class="toolbar-actions subscription-billing-tabs" aria-label="Subscription billing sections">
            <a class="button button-secondary" href="#subscription-overview">Overview</a>
            <a class="button button-secondary" href="#payment-verification">Payment Verification</a>
            <a class="button button-secondary" href="#payment-history">Payment History</a>
            <a class="button button-secondary" href="#refund-history">Refunds</a>
            <a class="button button-secondary" href="{{ route('super-admin.payments.reports') }}">Reports</a>
        </nav>
    @endunless

    <div class="page-tools" id="subscription-overview">
        <form method="GET" action="{{ $expiring ? route('super-admin.subscriptions.expiring') : route('super-admin.subscriptions.index') }}" class="toolbar-actions" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $search }}" placeholder="Search restaurant, owner, plan, subscription or reference">
            @unless($expiring)
                <select class="toolbar-search" name="status" aria-label="Filter by subscription status">
                    <option value="">All subscription statuses</option>
                    @foreach(['pending_payment' => 'Pending Payment', 'pending_approval' => 'Pending Approval', 'scheduled' => 'Scheduled', 'active' => 'Active', 'trial' => 'Trial', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'] as $value => $label)
                        <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select class="toolbar-search" name="payment_status" aria-label="Filter by payment status">
                    <option value="">All payment statuses</option>
                    @foreach(['unpaid' => 'Unpaid', 'pending' => 'Submitted / Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'refunded' => 'Refunded'] as $value => $label)
                        <option value="{{ $value }}" @selected($paymentStatusFilter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            @endunless
        </form>
        <a class="button button-secondary" href="{{ $expiring ? route('super-admin.subscriptions.expiring') : route('super-admin.subscriptions.index') }}" data-auto-filter-reset>Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr><th>Subscription</th><th>Restaurant / Owner</th><th>Plan / Amount</th><th>Payment status</th><th>Subscription status</th><th>Start</th><th>Expiry</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($subscriptions as $subscription)
                    @php
                        $effectiveStatus = $subscription->effectiveStatus();
                        $balance = $paymentSummaries[$subscription->id];
                        $hasPendingPayment = $subscription->payments->contains('status', 'pending');
                        $hasRejectedPayment = $subscription->payments->contains('status', 'rejected');
                        $paymentStatus = $balance['payment_status'];
                        if ($hasPendingPayment) {
                            $paymentStatus = 'Pending Verification';
                        } elseif ($hasRejectedPayment && $balance['already_paid'] === '0.00') {
                            $paymentStatus = 'Rejected';
                        }
                        $paymentStatusClass = str_replace(' ', '-', strtolower($paymentStatus));
                    @endphp
                    <tr>
                        <td><strong>{{ $subscription->formatted_id }}</strong><br><span class="meta-label">#{{ $subscription->id }}</span></td>
                        <td><strong>{{ $subscription->restaurant?->name ?? 'Restaurant unavailable' }}</strong><br><span class="meta-label">{{ $subscription->restaurant?->owners->first()?->name ?? 'No owner listed' }}</span></td>
                        <td>{{ $subscription->plan_name }}<br><span class="meta-label">{{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}</span></td>
                        <td><span class="status-pill status-{{ $paymentStatusClass }}">{{ $paymentStatus }}</span></td>
                        <td><span class="status-pill status-{{ str_replace('_', '-', $effectiveStatus) }}">{{ str_replace('_', ' ', ucfirst($effectiveStatus)) }}</span></td>
                        <td>{{ in_array($subscription->status, ['pending_payment', 'pending_approval'], true) ? '—' : $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td>{{ in_array($subscription->status, ['pending_payment', 'pending_approval'], true) ? '—' : $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td>
                            <div class="toolbar-actions">
                                <a class="button button-secondary" href="{{ route('super-admin.subscriptions.show', $subscription) }}">Manage</a>
                                @if(in_array($subscription->status, ['pending_payment', 'pending_approval'], true))
                                    <a class="button button-secondary" href="{{ route('super-admin.payments.create', ['restaurant_id' => $subscription->restaurant_id, 'restaurant_subscription_id' => $subscription->id]) }}">Record offline payment</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><h3>{{ $expiring ? 'No subscriptions expiring soon' : 'No subscriptions found' }}</h3><p>{{ $expiring ? 'There are no current plans or trials ending within 30 days.' : 'Assign a subscription plan to a restaurant to begin.' }}</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="page-tools pagination-tools">{{ $subscriptions->links() }}</div>

    @unless($expiring)
        <div class="section-heading" id="payment-verification"><div><h2>Payment verification queue</h2><p>Submitted payments remain unapproved until reviewed by a Super Admin.</p></div></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Payment</th><th>Restaurant / Owner</th><th>Subscription</th><th>Amount</th><th>Method / Reference</th><th>Submitted</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse($paymentQueue as $payment)
                        <tr>
                            <td>{{ $payment->formatted_id }}</td>
                            <td><strong>{{ $payment->restaurant?->name ?? 'Restaurant unavailable' }}</strong><br><span class="meta-label">{{ $payment->restaurant?->owners->first()?->name ?? 'No owner listed' }}</span></td>
                            <td>{{ $payment->subscription?->formatted_id ?? 'Historical / unlinked' }}<br><span class="meta-label">{{ $payment->plan_name ?: '—' }}</span></td>
                            <td>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                            <td>{{ $payment->payment_method_label ?? '—' }}<br><span class="meta-label">{{ $payment->transaction_reference ?: '—' }}</span></td>
                            <td>{{ $payment->paid_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</td>
                            <td><a class="button button-secondary" href="{{ route('super-admin.payments.show', $payment) }}">Review</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state"><h3>No payments awaiting review</h3><p>New owner submissions and offline entries will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="page-tools pagination-tools">{{ $paymentQueue->links() }}</div>

        <div class="section-heading" id="payment-history"><div><h2>Payment history</h2><p>Existing payment records remain unchanged and accessible here.</p></div><a class="button button-secondary" href="{{ route('super-admin.payments.export') }}">Export CSV</a></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Payment</th><th>Restaurant</th><th>Subscription</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse($paymentHistory as $payment)
                        <tr>
                            <td>{{ $payment->formatted_id }} @if($payment->isDemo())<span class="meta-label">DEMO ONLY — NOT ACTUAL MONEY</span>@endif<br><span class="meta-label">{{ $payment->transaction_reference ?: '—' }}</span></td>
                            <td>{{ $payment->restaurant?->name ?? '—' }}</td>
                            <td>{{ $payment->subscription?->formatted_id ?? 'Historical / unlinked' }}<br><span class="meta-label">{{ $payment->plan_name ?: '—' }}</span></td>
                            <td>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                            <td><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td>
                            <td>{{ $payment->paid_at?->timezone(config('app.timezone'))->format('M j, Y') ?? '—' }}</td>
                            <td><a class="button button-secondary" href="{{ route('super-admin.payments.show', $payment) }}">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state"><h3>No payment history</h3><p>All existing payment records will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="page-tools pagination-tools">{{ $paymentHistory->links() }}</div>

        <div class="section-heading" id="refund-history"><div><h2>Recorded refunds</h2><p>Refund entries are preserved as immutable financial records.</p></div></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Payment</th><th>Restaurant</th><th>Amount</th><th>Method / Reference</th><th>Reason</th><th>Recorded</th></tr></thead>
                <tbody>
                    @forelse($refundHistory as $refund)
                        <tr>
                            <td><a href="{{ route('super-admin.payments.show', $refund->payment) }}">{{ $refund->payment?->formatted_id ?? '—' }}</a></td>
                            <td>{{ $refund->payment?->restaurant?->name ?? '—' }}</td>
                            <td>{{ $refund->payment?->currency ?? 'INR' }} {{ number_format((float) $refund->amount, 2) }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $refund->method)) }}<br><span class="meta-label">{{ $refund->reference ?: '—' }}</span></td>
                            <td>{{ $refund->reason }}</td>
                            <td>{{ $refund->refunded_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><h3>No refunds recorded</h3><p>Recorded refunds will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="page-tools pagination-tools">{{ $refundHistory->links() }}</div>

        <section class="notice-card"><strong>Financial reports</strong><p>Use recorded payment and refund entries to review revenue by plan, restaurant, and date range.</p><a class="button button-secondary" href="{{ route('super-admin.payments.reports') }}">Open billing reports</a></section>
    @endunless
@endsection
