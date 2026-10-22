@extends('layouts.super-admin')

@section('title', 'Subscription · '.$subscription->restaurant->name)
@section('section', 'Restaurant Subscriptions')

@section('content')
    @php($effectiveStatus = $subscription->effectiveStatus())
    <div class="page-heading">
        <div>
            <span class="eyebrow">SUBSCRIPTION {{ $subscription->formatted_id }}</span>
            <h1>{{ $subscription->restaurant->name }}</h1>
            <p>{{ $subscription->plan_name }} · {{ $subscription->plan_code }} · {{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}</p>
        </div>
        <span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($effectiveStatus) }}">{{ \App\Support\StatusPresenter::label($effectiveStatus) }}</span>
    </div>

    <section class="panel-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Restaurant</span><strong>{{ $subscription->restaurant->name }} ({{ $subscription->restaurant->slug }})</strong></div>
            <div class="detail-item"><span>Plan</span><strong>{{ $subscription->plan_name }} · {{ $subscription->plan_code }}</strong></div>
            <div class="detail-item"><span>Billing</span><strong>{{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }} · {{ ucfirst($subscription->billing_period) }}</strong></div>
            <div class="detail-item"><span>Type</span><strong>{{ $subscription->is_trial ? 'Trial' : 'Standard' }}</strong></div>
            <div class="detail-item"><span>Start date</span><strong>{{ in_array($subscription->status, ['pending_payment', 'pending_approval'], true) ? 'After payment approval' : $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</strong></div>
            <div class="detail-item"><span>Expiry date</span><strong>{{ in_array($subscription->status, ['pending_payment', 'pending_approval'], true) ? 'After payment approval' : $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</strong></div>
            <div class="detail-item"><span>Assigned by</span><strong>{{ $subscription->creator?->name ?? 'Platform admin account unavailable' }}</strong></div>
            <div class="detail-item"><span>Renewal of</span><strong>{{ $subscription->renewal_of_id ? '#'.$subscription->renewal_of_id : 'Initial assignment' }}</strong></div>
        </div>
    </section>

    <div class="section-heading"><div><h2>Payments &amp; verification</h2><p>Payment status is separate from subscription access. Approval activates only a fully paid pending subscription.</p></div>
        @if(in_array($subscription->status, ['pending_payment', 'pending_approval'], true))
            <a class="button button-primary" href="{{ route('super-admin.payments.create', ['restaurant_id' => $subscription->restaurant_id, 'restaurant_subscription_id' => $subscription->id]) }}">Record offline payment</a>
        @endif
    </div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Payment</th><th>Amount</th><th>Method / Reference</th><th>Status</th><th>Submitted</th><th>Invoice / Actions</th></tr></thead>
            <tbody>
                @forelse($subscription->payments as $payment)
                    <tr>
                        <td>{{ $payment->formatted_id }}</td>
                        <td>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                        <td>{{ $payment->payment_method_label ?? '—' }}<br><span class="meta-label">{{ $payment->transaction_reference ?: '—' }}</span></td>
                        <td><span class="status-pill status-{{ \App\Support\StatusPresenter::badgeClass($payment->status) }}">{{ \App\Support\StatusPresenter::label($payment->status) }}</span></td>
                        <td>{{ $payment->paid_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? '—' }}</td>
                        <td>
                            @if($payment->invoice)
                                <a class="button button-secondary" href="{{ route('super-admin.invoices.show', $payment->invoice) }}">Invoice</a>
                            @endif
                            <a class="button button-secondary" href="{{ route('super-admin.payments.show', $payment) }}">{{ $payment->status === 'pending' ? 'Review' : 'Details' }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><h3>No payment records</h3><p>Payment submissions and approvals will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section-heading"><div><h2>Subscription actions</h2><p>Every lifecycle change is stored as a separate history event.</p></div></div>
    <section class="subscription-action-grid">
        @if(in_array($effectiveStatus, ['active', 'trial', 'expired'], true))
            <form method="POST" action="{{ route('super-admin.subscriptions.renew', $subscription) }}" class="panel-card form-stack" data-loading-form>
                @csrf
                <h3>Renew subscription</h3>
                <p class="field-hint">A new pending-payment period is created from the saved subscription terms. It does not grant access until verified payment is approved. An active period starts after its expiry; an expired period starts today.</p>
                @error('subscription')<span class="field-error">{{ $message }}</span>@enderror
                <button class="button button-primary" type="submit">Renew</button>
            </form>
        @endif

        @if(in_array($effectiveStatus, ['active', 'trial'], true))
            <form method="POST" action="{{ route('super-admin.subscriptions.extend', $subscription) }}" class="panel-card form-stack" data-loading-form>
                @csrf
                <h3>Extend expiry</h3>
                <p class="field-hint">Extending preserves this subscription record and appends a before/after event.</p>
                <div class="form-field"><label for="extend-expiry-date">New expiry date</label><input id="extend-expiry-date" name="expiry_date" type="date" required min="{{ now(config('app.timezone'))->addDay()->format('Y-m-d') }}" value="{{ old('expiry_date') }}">@error('expires_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                <button class="button button-secondary" type="submit">Extend expiry</button>
            </form>
        @endif

        <div class="panel-card form-stack">
            <h3>Subscription status</h3>
            @if($subscription->status === 'suspended' && $subscription->expires_at->gte(now(config('app.timezone'))))
                <form method="POST" action="{{ route('super-admin.subscriptions.status', $subscription) }}" data-loading-form data-confirm="Reactivate this subscription? Restaurant operational access will be restored while its term remains valid." data-confirm-title="Reactivate subscription?" data-confirm-action="Reactivate">
                    @csrf<input type="hidden" name="status" value="active"><button class="button button-primary" type="submit">Reactivate</button>
                </form>
            @elseif(in_array($subscription->status, ['active', 'trial'], true))
                <form method="POST" action="{{ route('super-admin.subscriptions.status', $subscription) }}" class="action-stack" data-loading-form data-confirm="Suspend this subscription? The restaurant will lose operational access immediately." data-confirm-title="Suspend subscription?" data-confirm-action="Suspend">
                    @csrf<input type="hidden" name="status" value="suspended"><button class="button button-danger" type="submit">Suspend</button>
                </form>
                <form method="POST" action="{{ route('super-admin.subscriptions.status', $subscription) }}" data-loading-form data-confirm="Cancel this subscription? The restaurant will lose operational access and this action cannot be undone." data-confirm-title="Cancel subscription?" data-confirm-action="Cancel subscription">
                    @csrf<input type="hidden" name="status" value="cancelled"><button class="button button-secondary" type="submit">Cancel subscription</button>
                </form>
            @else
                <p class="field-hint">No status change is available for this historical subscription record.</p>
            @endif
            @error('status')<span class="field-error">{{ $message }}</span>@enderror
        </div>
    </section>

    <div class="section-heading"><div><h2>History</h2><p>Append-only audit entries for this subscription.</p></div><a class="button button-secondary" href="{{ route('super-admin.subscriptions.history') }}">All history</a></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Event</th><th>Actor</th><th>Previous Status</th><th>New Status</th><th>Recorded</th></tr></thead>
            <tbody>
                @forelse($subscription->events as $event)
                    <tr>
                        <td>{{ \App\Support\StatusPresenter::label($event->action) }}</td>
                        <td>{{ $event->actor?->name ?? 'Platform admin account unavailable' }}</td>
                        <td>{{ \App\Support\StatusPresenter::label($event->previous_status) }}</td>
                        <td>{{ \App\Support\StatusPresenter::label($event->new_status) }}</td>
                        <td>{{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y H:i T') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><h3>No events recorded</h3><p>Subscription changes will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
