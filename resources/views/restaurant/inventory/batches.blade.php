@extends('layouts.restaurant-admin')

@section('title', 'Inventory Batches')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">FEFO stock tracking</span><h1>Batch and expiry tracking</h1><p>Expired batches are excluded from automatic recipe consumption.</p></div><a class="button button-primary" href="{{ route('restaurant.inventory.stock.create', ['kind' => 'in']) }}">Receive stock</a></div>
    <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Batch</th><th>Location</th><th>Vendor</th><th>Manufactured</th><th>Expires</th><th>Received</th><th>Available</th><th>Status</th></tr></thead><tbody>
        @forelse($batches as $batch)
            @php($expired = $batch->expires_at && \Illuminate\Support\Carbon::parse($batch->expires_at)->isBefore(today()))
            <tr><td>{{ $batch->name }}</td><td>{{ $batch->batch_number }}</td><td>{{ $batch->location_name }}</td><td>{{ $batch->vendor_name ?? '—' }}</td><td>{{ $batch->manufactured_at ? \Illuminate\Support\Carbon::parse($batch->manufactured_at)->format('M j, Y') : '—' }}</td><td>{{ $batch->expires_at ? \Illuminate\Support\Carbon::parse($batch->expires_at)->format('M j, Y') : '—' }}</td><td>{{ $batch->received_quantity }}</td><td>{{ $batch->available_quantity }}</td><td><span class="status-badge {{ $expired ? 'status-badge-danger' : 'status-badge-success' }}">{{ $expired ? 'Expired' : 'Available' }}</span></td></tr>
        @empty<tr><td colspan="9"><div class="empty-state"><h3>No tracked batches</h3><p>Batch data appears when receiving tracked inventory items.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($batches->hasPages())<div class="page-tools pagination-tools">{{ $batches->links() }}</div>@endif</section>
@endsection
