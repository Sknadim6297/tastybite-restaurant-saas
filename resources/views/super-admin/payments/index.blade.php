@extends('layouts.super-admin')

@section('title', 'Payments')
@section('section', 'Payments')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">FINANCIAL OPERATIONS</span><h1>Payments</h1><p>Manually recorded subscription payments and their approval status.</p></div>
        <div class="toolbar-actions"><a class="button button-secondary" href="{{ route('super-admin.payments.reports') }}">Reports</a>@if(($platformSettings['manual_enabled'] ?? true))<a class="button button-primary" href="{{ route('super-admin.payments.create') }}">Record payment</a>@endif</div>
    </div>

    <div class="summary-row payment-summary">
        <article class="summary-tile"><span>Total collected (real, net of refunds)</span><strong>INR {{ $summary['total_collected'] }}</strong></article>
        <article class="summary-tile"><span>Real payments this month</span><strong>INR {{ $summary['month'] }}</strong></article>
        <article class="summary-tile"><span>Real pending payments</span><strong>INR {{ $summary['pending'] }}</strong></article>
        <article class="summary-tile"><span>Real successful payments</span><strong>{{ number_format($summary['successful']) }}</strong></article>
        <article class="summary-tile"><span>Real failed / rejected</span><strong>{{ number_format($summary['rejected']) }}</strong></article>
        <article class="summary-tile"><span>Real refunds recorded</span><strong>INR {{ $summary['refunded'] }}</strong></article>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.payments.index') }}" class="payment-filters">
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search reference, restaurant, owner or plan" aria-label="Search payments">
            <select class="toolbar-search" name="restaurant_id" aria-label="Filter by restaurant"><option value="">All restaurants</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select>
            <select class="toolbar-search" name="status" aria-label="Filter by status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','refunded'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
            <select class="toolbar-search" name="scope" aria-label="Filter demo records"><option value="all" @selected(($filters['scope'] ?? 'all') === 'all')>All records</option><option value="real" @selected(($filters['scope'] ?? '') === 'real')>Real records</option><option value="demo" @selected(($filters['scope'] ?? '') === 'demo')>Demo records</option></select>
            <input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="From date">
            <input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="To date">
            <button class="button button-secondary" type="submit">Filter</button>
            <a class="button button-secondary" href="{{ route('super-admin.payments.index') }}">Reset</a>
            <a class="button button-secondary" href="{{ route('super-admin.payments.export', request()->query()) }}">Export CSV</a>
        </form>
    </div>

    <div class="table-shell">
        <table class="data-table payment-table">
            <thead><tr><th>Payment ID</th><th>Restaurant / Owner</th><th>Plan</th><th>Amount</th><th>Method / Reference</th><th>Status</th><th>Payment Date</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td>#{{ $payment->id }} @if($payment->isDemo())<span class="meta-label">Demo</span>@endif</td>
                        <td><strong>{{ $payment->restaurant->name }}</strong><br><span class="field-hint">{{ $payment->restaurant->owners->first()?->name ?? 'No owner listed' }}</span></td>
                        <td>{{ $payment->plan_name }}</td>
                        <td>{{ $payment->currency }} {{ $payment->amount }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}<br><span class="field-hint">{{ $payment->transaction_reference ?: 'No reference' }}</span></td>
                        <td><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td>
                        <td>{{ $payment->paid_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.payments.show', $payment) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><h3>No payments found</h3><p>Record a manual subscription payment or adjust your filters.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($payments->hasPages())<div class="page-tools pagination-tools">{{ $payments->links() }}</div>@endif
@endsection
