@extends('layouts.super-admin')

@section('title', $owner->name)
@section('section', 'Restaurant Owners')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">OWNER ACCOUNT</span>
            <h1>{{ $owner->name }}</h1>
            <p>{{ $owner->email }} · {{ $owner->restaurant?->name ?? 'Unassigned restaurant' }}</p>
        </div>
        <div class="toolbar-actions">
            <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.index') }}">Back to owners</a>
            <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.edit', $owner) }}">Edit owner</a>
            <form class="inline-form" method="POST" action="{{ route('super-admin.restaurant-owners.status', $owner) }}" data-loading-form data-confirm="{{ $owner->status === 'active' ? 'Disable' : 'Enable' }} access for {{ $owner->name }}? Existing protected sessions will be checked on the next request." data-confirm-title="{{ $owner->status === 'active' ? 'Disable owner?' : 'Enable owner?' }}" data-confirm-action="{{ $owner->status === 'active' ? 'Disable' : 'Enable' }}">
                @csrf
                <input type="hidden" name="status" value="{{ $owner->status === 'active' ? 'inactive' : 'active' }}">
                <button class="button {{ $owner->status === 'active' ? 'button-danger' : 'button-primary' }}" type="submit">{{ $owner->status === 'active' ? 'Disable owner' : 'Enable owner' }}</button>
            </form>
        </div>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ ucfirst($owner->status) }}</strong><span>Owner account status</span></div>
        <div class="summary-tile"><strong>{{ ucfirst($owner->restaurant?->status ?? 'unassigned') }}</strong><span>Restaurant status</span></div>
        <div class="summary-tile"><strong>{{ $subscription?->effectiveStatus() ? ucfirst($subscription->effectiveStatus()) : 'None' }}</strong><span>Subscription status</span></div>
    </div>

    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Owner name</span><strong>{{ $owner->name }}</strong></div>
            <div class="detail-item"><span>Email</span><strong>{{ $owner->email }}</strong></div>
            <div class="detail-item"><span>Restaurant</span><strong>{{ $owner->restaurant?->name ?? 'Unassigned' }}</strong></div>
            <div class="detail-item"><span>Role</span><strong>{{ ucfirst($owner->role) }}</strong></div>
            <div class="detail-item"><span>Account status</span><strong>{{ ucfirst($owner->status) }}</strong></div>
            <div class="detail-item"><span>Restaurant status</span><strong>{{ ucfirst($owner->restaurant?->status ?? 'unassigned') }}</strong></div>
            <div class="detail-item"><span>Account created</span><strong>{{ $owner->created_at?->format('F j, Y H:i') ?? '—' }}</strong></div>
            <div class="detail-item"><span>Last successful login</span><strong>{{ $owner->last_login_at?->format('F j, Y H:i') ?? 'No recorded login' }}</strong></div>
            <div class="detail-item"><span>Subscription plan</span><strong>{{ $subscription?->plan_name ?? 'No subscription assigned' }}</strong></div>
            <div class="detail-item"><span>Subscription expiry</span><strong>{{ $subscription?->expires_at?->timezone(config('app.timezone'))->format('F j, Y H:i') ?? '—' }}</strong></div>
        </div>
    </div>

    <div class="owner-security-actions">
        <div>
            <h2>Password setup and reset</h2>
            <p>Send a single-use, expiring link to the owner’s registered email address.</p>
        </div>
        <form method="POST" action="{{ route('super-admin.restaurant-owners.reset-password', $owner) }}" data-loading-form data-confirm="Send a single-use password setup/reset link to {{ $owner->email }}?" data-confirm-title="Send password link?" data-confirm-action="Send link">
            @csrf
            <button class="button button-secondary" type="submit">
                <span class="button-label">Send password reset link</span>
                <span class="button-spinner" aria-hidden="true"></span>
            </button>
        </form>
    </div>

    <div class="section-heading">
        <div><h2>Owner activity and audit history</h2><p>Recent account changes and successful sign-ins. No credentials or reset tokens are recorded.</p></div>
    </div>
    <div class="table-shell">
        <table class="data-table owner-audit-table">
            <thead><tr><th>Action</th><th>Actor</th><th>Timestamp</th><th>Details</th></tr></thead>
            <tbody>
                @forelse ($auditLogs as $log)
                    <tr>
                        <td>{{ str_replace('_', ' ', $log->action) }}</td>
                        <td>{{ $log->actor?->name ?? ($log->actor_type === 'restaurant_owner' ? 'Owner sign-in' : ucfirst($log->actor_type)) }}</td>
                        <td>{{ $log->created_at?->format('M j, Y H:i') ?? '—' }}</td>
                        <td>@include('super-admin.activity-logs._details', ['details' => $log->getRawOriginal('details')])</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state"><h3>No owner activity yet</h3><p>Account changes and future successful logins will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
