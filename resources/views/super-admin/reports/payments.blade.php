@extends('layouts.super-admin')

@section('title', 'Payment Report')
@section('section', 'Reports')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PLATFORM INSIGHTS</span><h1>Payment report</h1><p>Payment records shown here are separate from the financial summary until approved.</p></div><a class="button button-secondary" href="{{ route('super-admin.reports.export', array_merge(request()->query(), ['type' => 'payments'])) }}">Export CSV</a></div>
    @include('super-admin.reports._tabs', ['active' => 'payments'])
    <form method="GET" class="form-card report-filters">
        <label>Data scope<select name="scope"><option value="real" @selected($filters['scope'] === 'real')>Real records</option><option value="demo" @selected($filters['scope'] === 'demo')>Demo records</option><option value="all" @selected($filters['scope'] === 'all')>All records</option></select></label>
        <label>Restaurant<select name="restaurant_id"><option value="">All restaurants</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select></label>
        <label>Plan ID<input type="number" min="1" name="plan_id" value="{{ $filters['plan_id'] ?? '' }}"></label>
        <label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','refunded'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label>Payment date from<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label>Payment date to<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <div class="report-filter-actions"><button class="button button-primary">Apply filters</button><a class="button button-secondary" href="{{ route('super-admin.reports.payments') }}">Clear</a></div>
    </form>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Payment</th><th>Restaurant</th><th>Plan</th><th>Amount</th><th>Status</th><th>Paid at</th><th>Refunds recorded</th></tr></thead><tbody>
        @forelse($payments as $payment)
            <tr><td><a class="table-primary-link" href="{{ route('super-admin.payments.show', $payment) }}">#{{ $payment->id }}</a><small>{{ $payment->transaction_reference ?: 'No reference' }}</small></td><td>{{ $payment->restaurant->name }}</td><td>{{ $payment->plan_name }}</td><td>{{ $payment->currency }} {{ $payment->amount }}</td>
                <td><span class="status-pill status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td><td>{{ $payment->paid_at?->format(config('app.date_format', 'M j, Y')) }}</td><td>{{ $payment->refunds->sum('amount') }}</td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No payments found</h3><p>Adjust filters or select a different data scope.</p></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-wrap">{{ $payments->links() }}</div>
@endsection
