@extends('layouts.restaurant-admin')

@section('title', 'Purchase Orders')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PURCHASING</span><h1>Purchase orders</h1><p>Track supplier orders, delivery status, and ordered amounts.</p></div><div class="toolbar-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.overview') }}">Vendor Overview</a><a class="button button-primary" href="{{ route('restaurant.vendors.purchase-orders.create') }}">Create purchase order</a></div></div>
    <div class="page-tools"><form method="GET" action="{{ route('restaurant.vendors.purchase-orders.index') }}" class="toolbar-actions" data-auto-filter>
        <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="PO number or vendor" aria-label="Search purchase orders">
        <select class="toolbar-search" name="vendor_id" aria-label="Filter by vendor"><option value="">All vendors</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) ($filters['vendor_id'] ?? '') === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select>
        <select class="toolbar-search" name="status" aria-label="Filter by status"><option value="">All statuses</option>@foreach(['draft','confirmed','partially_received','received','cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \Illuminate\Support\Str::headline($status) }}</option>@endforeach</select>
        <input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="Order date from"><input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="Order date to">
        <a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.index') }}" data-auto-filter-reset>Reset</a>
    </form></div>
    <div class="table-shell"><table class="data-table"><thead><tr><th>PO number</th><th>Vendor</th><th>Order date</th><th>Expected delivery</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>
        @forelse($orders as $order)<tr><td><a class="table-primary-link" href="{{ route('restaurant.vendors.purchase-orders.show', $order) }}">{{ $order->po_number ?? 'Draft #'.$order->id }}</a></td><td>{{ $order->vendor?->name ?? 'Vendor unavailable' }}</td><td>{{ $order->order_date?->format('M j, Y') ?? '—' }}</td><td>{{ $order->expected_delivery_date?->format('M j, Y') ?? '—' }}</td><td><span class="status-pill status-{{ $order->status === 'received' ? 'active' : (str_contains($order->status, 'received') ? 'pending' : ($order->status === 'cancelled' ? 'disabled' : 'pending')) }}">{{ \Illuminate\Support\Str::headline($order->status) }}</span></td><td>INR {{ number_format((float) $order->grand_total, 2) }}</td><td><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.show', $order) }}">View</a></td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No purchase orders</h3><p>Create a purchase order to record planned vendor purchases.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($orders->hasPages())<div class="page-tools pagination-tools">{{ $orders->links() }}</div>@endif
@endsection