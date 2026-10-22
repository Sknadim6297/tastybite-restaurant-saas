@extends('layouts.restaurant-admin')

@section('title', 'Goods Received')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PURCHASING</span><h1>Goods received</h1><p>Receipt history for deliveries against purchase orders.</p></div><a class="button button-secondary" href="{{ route('restaurant.vendors.purchase-orders.index') }}">Purchase Orders</a></div>
    <div class="page-tools"><form method="GET" class="toolbar-actions" data-auto-filter><select class="toolbar-search" name="vendor_id" aria-label="Filter by vendor"><option value="">All vendors</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) ($filters['vendor_id'] ?? '') === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach</select><input class="toolbar-search" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="Received from"><input class="toolbar-search" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="Received to"><a class="button button-secondary" href="{{ route('restaurant.vendors.goods-received.index') }}" data-auto-filter-reset>Reset</a></form></div>
    <div class="table-shell"><table class="data-table"><thead><tr><th>GRN</th><th>PO</th><th>Vendor</th><th>Date</th><th>Received by</th><th>Lines</th></tr></thead><tbody>
        @forelse($receipts as $receipt)<tr><td><a class="table-primary-link" href="{{ route('restaurant.vendors.goods-received.show', $receipt) }}">{{ $receipt->grn_number }}</a></td><td><a class="table-primary-link" href="{{ route('restaurant.vendors.purchase-orders.show', $receipt->purchaseOrder) }}">{{ $receipt->purchaseOrder?->po_number ?? '—' }}</a></td><td>{{ $receipt->vendor?->name ?? 'Vendor unavailable' }}</td><td>{{ $receipt->received_date?->format('M j, Y') }}</td><td>{{ $receipt->receiver?->name ?? '—' }}</td><td>{{ $receipt->items->count() }}</td></tr>
        @empty<tr><td colspan="6"><div class="empty-state"><h3>No goods receipts</h3><p>Receipts recorded against purchase orders will appear here.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($receipts->hasPages())<div class="page-tools pagination-tools">{{ $receipts->links() }}</div>@endif
@endsection