@extends('layouts.super-admin')

@section('title', $restaurant->name)
@section('section', 'Restaurants')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">RESTAURANT DETAIL</span>
            <h1>{{ $restaurant->name }}</h1>
            <p>Tenant profile and operational status.</p>
        </div>
        <div class="toolbar-actions">
            <a class="button button-secondary" href="{{ route('super-admin.restaurants.index') }}">Back to list</a>
            <a class="button button-secondary" href="{{ route('super-admin.restaurants.edit', $restaurant) }}">Edit</a>
            @if ($restaurant->status === 'active' && $restaurant->approval_status === 'approved' && $restaurant->owners->contains(fn ($restaurantOwner) => $restaurantOwner->status === 'active'))
                <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.dashboard', $restaurant) }}" target="_blank" rel="noopener noreferrer">
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
    </div>

    <div class="summary-row">
        <div class="summary-tile">
            <strong>{{ \App\Support\StatusPresenter::label($restaurant->status) }}</strong>
            <span>Restaurant status</span>
        </div>
        <div class="summary-tile">
            <strong>{{ $restaurant->owners->count() }}</strong>
            <span>Owner accounts</span>
        </div>
        <div class="summary-tile">
            <strong>{{ $restaurant->auditLogs->count() }}</strong>
            <span>Recent audit entries</span>
        </div>
    </div>

    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Restaurant name</span><strong>{{ $restaurant->name }}</strong></div>
            <div class="detail-item"><span>Slug</span><strong>{{ $restaurant->slug }}</strong></div>
            <div class="detail-item"><span>Email</span><strong>{{ $restaurant->email }}</strong></div>
            <div class="detail-item"><span>Phone</span><strong>{{ $restaurant->phone ?? '—' }}</strong></div>
            <div class="detail-item full-span"><span>Address</span><strong>{{ $restaurant->address ?? '—' }}</strong></div>
            <div class="detail-item"><span>Status</span><strong>{{ \App\Support\StatusPresenter::label($restaurant->status) }}</strong></div>
            <div class="detail-item"><span>Created</span><strong>{{ $restaurant->created_at->format('F j, Y') }}</strong></div>
        </div>
    </div>

    <div class="section-heading" style="margin-top:32px;">
        <div><h2>Owner management</h2><p>Secure owner access and reset posture.</p></div>
    </div>

    <div class="form-card">
        @if ($owner)
            <div class="detail-grid">
                <div class="detail-item"><span>Owner name</span><strong>{{ $owner->name }}</strong></div>
                <div class="detail-item"><span>Email</span><strong>{{ $owner->email }}</strong></div>
                <div class="detail-item"><span>Mobile number</span><strong>{{ $owner->mobile ?: 'Not provided' }}</strong></div>
                <div class="detail-item"><span>Role</span><strong>{{ ucfirst($owner->role) }}</strong></div>
                <div class="detail-item"><span>Status</span><strong>{{ \App\Support\StatusPresenter::label($owner->status) }}</strong></div>
            </div>
            <div class="page-tools" style="margin-top:24px; margin-bottom:0; justify-content:flex-end;">
                <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.edit', $owner) }}">Edit owner details</a>
                <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.owners.toggle-status', [$restaurant, $owner]) }}" data-loading-form data-confirm="{{ $owner->status === 'active' ? 'Disable' : 'Enable' }} owner access for {{ $owner->name }}?" data-confirm-title="{{ $owner->status === 'active' ? 'Disable owner?' : 'Enable owner?' }}" data-confirm-action="{{ $owner->status === 'active' ? 'Disable' : 'Enable' }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ $owner->status === 'active' ? 'inactive' : 'active' }}">
                    <button class="button {{ $owner->status === 'active' ? 'button-danger' : 'button-primary' }}" type="submit">
                        {{ $owner->status === 'active' ? 'Disable owner' : 'Enable owner' }}
                    </button>
                </form>
                <form class="inline-form" method="POST" action="{{ route('super-admin.restaurants.owners.reset-password', [$restaurant, $owner]) }}" data-loading-form data-confirm="Send a single-use, expiring password setup/reset link to {{ $owner->email }}?" data-confirm-title="Send password link?" data-confirm-action="Send link">
                    @csrf
                    <button class="button button-secondary" type="submit">Send reset link</button>
                </form>
            </div>
        @else
            <div class="empty-state">
                <h3>No owner assigned</h3>
                <p>This restaurant does not have a registered owner account yet.</p>
            </div>
        @endif
    </div>

    <div class="section-heading" style="margin-top:32px;">
        <div><h2>Audit log</h2><p>Recent platform actions for this restaurant.</p></div>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Actor</th>
                    <th>Timestamp</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($restaurant->auditLogs as $log)
                    <tr>
                        <td>{{ str_replace('_', ' ', $log->action) }}</td>
                        <td>{{ $log->actor?->name ?? ucfirst($log->actor_type) }}</td>
                        <td>{{ $log->created_at->format('M j, Y H:i') }}</td>
                        <td>@include('super-admin.activity-logs._details', ['details' => $log->getRawOriginal('details')])</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <h3>No audit entries</h3>
                                <p>No actions have been recorded for this restaurant yet.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
