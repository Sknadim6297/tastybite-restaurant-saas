@extends('layouts.super-admin')

@section('title', 'Restaurants')
@section('section', 'Restaurants')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">RESTAURANT NETWORK</span>
            <h1>Restaurants</h1>
            <p>Manage restaurant onboarding, tenant status, and owner access.</p>
        </div>
        <a class="button button-primary" href="{{ route('super-admin.restaurants.create') }}">Add restaurant</a>
    </div>

    <div class="summary-row">
        <div class="summary-tile">
            <strong>{{ $restaurantStats->total }}</strong>
            <span>Total restaurants</span>
        </div>
        <div class="summary-tile">
            <strong>{{ $restaurantStats->active }}</strong>
            <span>Active</span>
        </div>
        <div class="summary-tile">
            <strong>{{ $restaurantStats->suspended }}</strong>
            <span>Suspended</span>
        </div>
        <div class="summary-tile">
            <strong>{{ $restaurantStats->inactive }}</strong>
            <span>Inactive</span>
        </div>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.restaurants.index') }}" class="toolbar-actions" style="flex:1;" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $search }}" placeholder="Search name, slug, or email">
            <select class="toolbar-search" name="status" aria-label="Filter by status" style="max-width:180px;">
                <option value="">All statuses</option>
                <option value="active" @selected($statusFilter === 'active')>Active</option>
                <option value="suspended" @selected($statusFilter === 'suspended')>Suspended</option>
                <option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option>
            </select>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.restaurants.index') }}" data-auto-filter-reset>Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr>
                    <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'name', 'direction' => $sort === 'name' && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1]) }}">Restaurant @if($sort === 'name'){{ $direction === 'asc' ? '↑' : '↓' }}@endif</a></th>
                    <th>Owner</th>
                    <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'email', 'direction' => $sort === 'email' && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1]) }}">Email @if($sort === 'email'){{ $direction === 'asc' ? '↑' : '↓' }}@endif</a></th>
                    <th>Phone</th>
                    <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'direction' => $sort === 'status' && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1]) }}">Status @if($sort === 'status'){{ $direction === 'asc' ? '↑' : '↓' }}@endif</a></th>
                    <th><a href="{{ request()->fullUrlWithQuery(['sort' => 'created_at', 'direction' => $sort === 'created_at' && $direction === 'asc' ? 'desc' : 'asc', 'page' => 1]) }}">Created @if($sort === 'created_at'){{ $direction === 'asc' ? '↑' : '↓' }}@endif</a></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($restaurants as $restaurant)
                    @php($owner = $restaurant->owners->first())
                    <tr>
                        <td>
                            <strong>{{ $restaurant->name }}</strong><br>
                            <span class="meta-label" style="margin-top:6px;">{{ $restaurant->slug }}</span>
                        </td>
                        <td>{{ $owner?->name ?? '—' }}</td>
                        <td>{{ $restaurant->email }}</td>
                        <td>{{ $restaurant->phone ?? '—' }}</td>
                        <td>
                            <span class="status-pill status-{{ $restaurant->status === 'active' ? 'active' : ($restaurant->status === 'suspended' ? 'suspended' : 'inactive') }}">
                                {{ ucfirst($restaurant->status) }}
                            </span>
                        </td>
                        <td>{{ $restaurant->created_at->format('M j, Y') }}</td>
                        <td>
                            <div class="action-stack">
                                <a class="button button-secondary" href="{{ route('super-admin.restaurants.show', $restaurant) }}">View</a>
                                <a class="button button-secondary" href="{{ route('super-admin.restaurants.edit', $restaurant) }}">Edit</a>
                                @if ($restaurant->status === 'active' && $restaurant->approval_status === 'approved' && $restaurant->owners->contains(fn ($restaurantOwner) => $restaurantOwner->status === 'active'))
                                    <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.dashboard', $restaurant) }}">
                                        @csrf
                                        <button class="button button-primary" type="submit">Go to Dashboard</button>
                                    </form>
                                @endif
                                @if ($restaurant->status !== 'active')
                                    <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.toggle-status', $restaurant) }}" data-loading-form data-confirm="Reactivate {{ $restaurant->name }}? Restaurant Admin access will be restored." data-confirm-title="Reactivate restaurant?" data-confirm-action="Reactivate">
                                        @csrf
                                        <input type="hidden" name="status" value="active">
                                        <button class="button button-primary" type="submit">Activate</button>
                                    </form>
                                @else
                                    <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.toggle-status', $restaurant) }}" data-loading-form data-confirm="Suspend {{ $restaurant->name }}? Its users will lose access to protected pages." data-confirm-title="Suspend restaurant?" data-confirm-action="Suspend">
                                        @csrf
                                        <input type="hidden" name="status" value="suspended">
                                        <button class="button button-danger" type="submit">Suspend</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <h3>No restaurants found</h3>
                                <p>Create the first restaurant and owner account to begin platform onboarding.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($restaurants->hasPages())
        <div class="page-tools" style="margin-top:16px; justify-content:flex-end;">
            {{ $restaurants->links() }}
        </div>
    @endif
@endsection
