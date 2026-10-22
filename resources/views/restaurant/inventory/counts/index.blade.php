@extends('layouts.restaurant-admin')

@section('title', 'Stock Counts')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Physical stock verification</span><h1>Stock counts</h1><p>Capture physical quantities, review variances, then post auditable adjustments.</p></div></div>
    <section class="panel-card"><h2>Start a stock count</h2><form method="POST" action="{{ route('restaurant.inventory.counts.store') }}" class="form-grid">@csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        <div class="form-field"><label for="location">Stock location</label><select id="location" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
        <div class="form-field"><label for="counted_at">Count date</label><input id="counted_at" type="datetime-local" name="counted_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
        <div class="form-field"><label for="notes">Notes</label><input id="notes" name="notes" maxlength="2000"></div>
        <div class="form-field"><button class="button button-primary" type="submit">Start count</button></div>
    </form></section>
    <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Count</th><th>Location</th><th>Started</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @forelse($counts as $count)<tr><td>{{ $count->count_number }}</td><td>{{ $locations->firstWhere('id', $count->location_id)?->name ?? '—' }}</td><td>{{ \Illuminate\Support\Carbon::parse($count->counted_at)->format('M j, Y H:i') }}</td><td><span class="status-badge {{ $count->status === 'posted' ? 'status-badge-success' : 'status-badge-warning' }}">{{ ucfirst($count->status) }}</span></td><td><a href="{{ route('restaurant.inventory.counts.show', $count->id) }}">{{ $count->status === 'posted' ? 'View' : 'Continue' }}</a></td></tr>
        @empty<tr><td colspan="5"><div class="empty-state"><h3>No stock counts yet</h3><p>Start a count to compare physical quantities with current stock.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($counts->hasPages())<div class="page-tools pagination-tools">{{ $counts->links() }}</div>@endif</section>
@endsection
