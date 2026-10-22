@extends('layouts.super-admin')

@section('title', 'Revenue Report')
@section('section', 'Reports')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PLATFORM INSIGHTS</span><h1>Revenue report</h1><p>Collection totals include approved payments only and subtract recorded refunds. Recorded refunds do not confirm an external money transfer.</p></div><a class="button button-secondary" href="{{ route('super-admin.reports.export', array_merge(request()->query(), ['type' => 'revenue'])) }}">Export CSV</a></div>
    @include('super-admin.reports._tabs', ['active' => 'revenue'])
    <form method="GET" class="form-card report-filters" data-auto-filter>
        <label>Restaurant ID<input type="number" min="1" name="restaurant_id" value="{{ $filters['restaurant_id'] ?? '' }}"></label>
        <label>Plan ID<input type="number" min="1" name="plan_id" value="{{ $filters['plan_id'] ?? '' }}"></label>
        <label>Payment date from<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label>Payment date to<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <div class="report-filter-actions"><a class="button button-secondary" href="{{ route('super-admin.reports.revenue') }}" data-auto-filter-reset>Clear</a></div>
    </form>
    <section class="summary-row report-summary">
        <article class="summary-tile"><span>Approved gross payments</span><strong>INR {{ $gross }}</strong></article>
        <article class="summary-tile"><span>Refunds recorded</span><strong>INR {{ $refunds }}</strong></article>
        <article class="summary-tile"><span>Net recorded collections</span><strong>INR {{ $net }}</strong></article>
    </section>
    <div class="report-note">Refund entries are administrative records. They do not verify that funds were returned through a bank or payment provider.</div>
    @foreach([['Revenue by date', $byDay], ['Revenue by restaurant', $byRestaurant], ['Revenue by subscription plan', $byPlan]] as [$heading, $rows])
        <div class="section-heading"><div><h2>{{ $heading }}</h2></div></div>
        <div class="table-shell"><table class="data-table"><thead><tr><th>{{ $heading === 'Revenue by date' ? 'Date' : 'Group' }}</th><th>Approved payments</th><th>Gross amount</th></tr></thead><tbody>
            @forelse($rows as $row)<tr><td>{{ $row->label }}</td><td>{{ number_format($row->payment_count) }}</td><td>INR {{ $row->gross }}</td></tr>
            @empty<tr><td colspan="3"><div class="empty-state"><h3>No approved payments in this range</h3><p>Adjust the dates to review another period.</p></div></td></tr>@endforelse
        </tbody></table></div>
    @endforeach
@endsection
