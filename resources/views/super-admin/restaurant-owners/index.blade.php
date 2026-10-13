@extends('layouts.super-admin')

@section('title', 'Restaurant owners')
@section('section', 'Restaurant Owners')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">TENANT ACCOUNTS</span>
            <h1>Restaurant Owners</h1>
            <p>Manage owner access and profiles for existing restaurants.</p>
        </div>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ $stats->total }}</strong><span>Total owners</span></div>
        <div class="summary-tile"><strong>{{ $stats->active }}</strong><span>Active accounts</span></div>
        <div class="summary-tile"><strong>{{ $stats->inactive }}</strong><span>Disabled accounts</span></div>
    </div>

    <form class="owner-filter-card" method="GET" action="{{ route('super-admin.restaurant-owners.index') }}">
        <div class="form-field owner-search-field">
            <label for="search">Search owners</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, email, or restaurant">
        </div>
        <div class="form-field">
            <label for="restaurant_id">Restaurant</label>
            <select id="restaurant_id" name="restaurant_id">
                <option value="">All restaurants</option>
                @foreach ($restaurants as $restaurant)
                    <option value="{{ $restaurant->id }}" @selected((string) ($filters['restaurant_id'] ?? '') === (string) $restaurant->id)>{{ $restaurant->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label for="status">Owner status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Disabled</option>
            </select>
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <div class="owner-filter-actions">
            <button class="button button-primary" type="submit">Apply filters</button>
            <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.index') }}">Clear</a>
        </div>
    </form>

    <div class="table-shell">
        <table class="data-table owner-table">
            <thead>
                <tr>
                    @foreach (['name' => 'Owner', 'email' => 'Email', 'restaurant' => 'Restaurant', 'status' => 'Owner status', 'created_at' => 'Created', 'last_login_at' => 'Last login'] as $field => $label)
                        @php
                            $nextDirection = $sort === $field && $direction === 'asc' ? 'desc' : 'asc';
                        @endphp
                        <th>
                            <a href="{{ route('super-admin.restaurant-owners.index', array_merge(request()->query(), ['sort' => $field, 'direction' => $nextDirection])) }}">
                                {{ $label }} @if ($sort === $field)<span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </a>
                        </th>
                    @endforeach
                    <th>Restaurant status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($owners as $owner)
                    <tr>
                        <td><strong>{{ $owner->name }}</strong></td>
                        <td>{{ $owner->email }}</td>
                        <td>
                            @if ($owner->restaurant)
                                <a href="{{ route('super-admin.restaurants.show', $owner->restaurant) }}">{{ $owner->restaurant->name }}</a>
                            @else
                                <span class="muted-copy">Unassigned</span>
                            @endif
                        </td>
                        <td><span class="status-pill {{ $owner->status === 'active' ? 'status-active' : 'status-inactive' }}">{{ $owner->status === 'active' ? 'Active' : 'Disabled' }}</span></td>
                        <td>{{ $owner->created_at?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $owner->last_login_at?->format('M j, Y H:i') ?? 'Never' }}</td>
                        <td>
                            @if ($owner->restaurant)
                                <span class="status-pill {{ $owner->restaurant->status === 'active' ? 'status-active' : ($owner->restaurant->status === 'suspended' ? 'status-suspended' : 'status-inactive') }}">{{ ucfirst($owner->restaurant->status) }}</span>
                            @else
                                <span class="status-pill status-inactive">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-stack">
                                <a class="button button-small button-secondary" href="{{ route('super-admin.restaurant-owners.show', $owner) }}">View</a>
                                <a class="button button-small button-secondary" href="{{ route('super-admin.restaurant-owners.edit', $owner) }}">Edit</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <h3>No restaurant owners found</h3>
                                <p>Adjust your search or filters. New primary owners are created with their restaurant.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="page-tools pagination-tools">
        {{ $owners->links() }}
    </div>
@endsection
