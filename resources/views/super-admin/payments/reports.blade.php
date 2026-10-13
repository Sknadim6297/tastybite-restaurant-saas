@extends('layouts.super-admin')

@section('title', 'Payment Reports')
@section('section', 'Payments')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">FINANCIAL REPORTING</span><h1>Payment reports</h1><p>All values are derived from recorded payments and refunds.</p></div><div class="toolbar-actions"><a class="button button-secondary" href="{{ route('super-admin.payments.index') }}">Payments</a><a class="button button-secondary" href="{{ route('super-admin.payments.export', request()->query()) }}">Export filtered CSV</a></div></div>
    <form method="GET" action="{{ route('super-admin.payments.reports') }}" class="payment-filters report-filter">
        <select class="toolbar-search" name="restaurant_id" aria-label="Filter by restaurant"><option value="">All restaurants</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select>
        <select class="toolbar-search" name="status" aria-label="Filter by status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','refunded'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
        <select class="toolbar-search" name="scope" aria-label="Select demo or real data"><option value="real" @selected(($filters['scope'] ?? 'real') === 'real')>Real records</option><option value="demo" @selected(($filters['scope'] ?? '') === 'demo')>Demo records</option><option value="all" @selected(($filters['scope'] ?? '') === 'all')>All records</option></select>
        <input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="From date"><input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="To date"><button class="button button-secondary" type="submit">Apply filters</button>
    </form>
    <div class="summary-row"><article class="summary-tile"><span>Gross approved payments ({{ $filters['scope'] }} scope)</span><strong>INR {{ number_format((float) $gross, 2) }}</strong></article><article class="summary-tile"><span>Refunds recorded in selected scope</span><strong>INR {{ number_format((float) $refundTotal, 2) }}</strong></article><article class="summary-tile"><span>Net collected in selected scope</span><strong>INR {{ number_format((float) $net, 2) }}</strong></article></div>

    @foreach([['Payments by date', $byDate], ['Payments by restaurant', $byRestaurant], ['Payments by subscription plan', $byPlan], ['Payment status breakdown', $byStatus]] as [$heading, $rows])
        <div class="section-heading"><div><h2>{{ $heading }}</h2></div></div>
        <div class="table-shell report-table"><table class="data-table"><thead><tr><th>{{ $heading === 'Payments by date' ? 'Date' : 'Group' }}</th><th>Payments</th><th>Gross approved amount</th></tr></thead><tbody>
            @forelse($rows as $row)<tr><td>{{ $row->label }}</td><td>{{ number_format($row->payment_count) }}</td><td>INR {{ number_format((float) $row->gross, 2) }}</td></tr>
            @empty<tr><td colspan="3"><div class="empty-state"><h3>No records in this range</h3><p>Try changing the selected filters.</p></div></td></tr>@endforelse
        </tbody></table></div>
    @endforeach
@endsection
