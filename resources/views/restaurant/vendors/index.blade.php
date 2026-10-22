@extends('layouts.restaurant-admin')

@section('title', 'All Vendors')
@section('section', 'Vendor Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PURCHASING</span><h1>All vendors</h1><p>Manage supplier contacts and account status for {{ $restaurant->name }}.</p></div>
        <div class="toolbar-actions"><a class="button button-secondary" href="{{ route('restaurant.vendors.overview') }}">Overview</a><a class="button button-primary" href="{{ route('restaurant.vendors.create') }}">Add vendor</a></div>
    </div>
    <div class="page-tools">
        <form method="GET" action="{{ route('restaurant.vendors.index') }}" class="toolbar-actions" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search vendor, contact or code" aria-label="Search vendors">
            <select class="toolbar-search" name="status" aria-label="Filter by status"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select>
            <select class="toolbar-search" name="category" aria-label="Filter by category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select>
            <select class="toolbar-search" name="sort" aria-label="Sort vendors"><option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Name</option><option value="vendor_code" @selected(($filters['sort'] ?? '') === 'vendor_code')>Vendor code</option><option value="category" @selected(($filters['sort'] ?? '') === 'category')>Category</option><option value="created_at" @selected(($filters['sort'] ?? '') === 'created_at')>Created date</option></select>
            <select class="toolbar-search" name="direction" aria-label="Sort direction"><option value="asc" @selected(($filters['direction'] ?? 'asc') === 'asc')>Ascending</option><option value="desc" @selected(($filters['direction'] ?? '') === 'desc')>Descending</option></select>
        </form>
        <a class="button button-secondary" href="{{ route('restaurant.vendors.index') }}" data-auto-filter-reset>Reset</a>
    </div>
    <div class="table-shell"><table class="data-table"><thead><tr><th>Vendor code</th><th>Vendor name</th><th>Contact</th><th>Mobile</th><th>Category</th><th>Outstanding</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @forelse($vendors as $vendor)
            @php($balance = $balances[$vendor->id] ?? 0)
            <tr>
                <td>{{ $vendor->vendor_code }}</td>
                <td><a class="table-primary-link" href="{{ route('restaurant.vendors.show', $vendor) }}">{{ $vendor->name }}</a></td>
                <td>{{ $vendor->contact_person ?: '—' }}</td>
                <td>{{ $vendor->mobile }}</td>
                <td>{{ $vendor->category ?: '—' }}</td>
                <td>INR {{ $money::formatCents((int) $balance) }}</td>
                <td><span class="status-pill status-{{ $vendor->status === 'active' ? 'active' : 'disabled' }}">{{ ucfirst($vendor->status) }}</span></td>
                <td><div class="action-stack">
                    <a class="button button-secondary" href="{{ route('restaurant.vendors.show', $vendor) }}">View</a>
                    <a class="button button-secondary" href="{{ route('restaurant.vendors.edit', $vendor) }}">Edit</a>
                    <form method="POST" action="{{ route('restaurant.vendors.status', $vendor) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $vendor->status === 'active' ? 'inactive' : 'active' }}"><button class="button button-secondary" type="submit">{{ $vendor->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
                    <form method="POST" action="{{ route('restaurant.vendors.archive', $vendor) }}" data-confirm="Archive this vendor? Vendors with purchasing or financial history cannot be archived.">@csrf @method('DELETE')<button class="button button-danger" type="submit">Archive</button></form>
                </div></td>
            </tr>
        @empty<tr><td colspan="8"><div class="empty-state"><h3>No vendors found</h3><p>Add a vendor or adjust your filters.</p></div></td></tr>@endforelse
    </tbody></table></div>
    @if($vendors->hasPages())<div class="page-tools pagination-tools">{{ $vendors->links() }}</div>@endif
@endsection