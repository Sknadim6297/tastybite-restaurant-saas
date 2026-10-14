@extends('layouts.super-admin')

@section('title', $expiring ? 'Expiring Soon' : 'Restaurant Subscriptions')
@section('section', $expiring ? 'Expiring Soon' : 'Restaurant Subscriptions')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">SUBSCRIPTION OPERATIONS</span>
            <h1>{{ $expiring ? 'Expiring soon' : 'Restaurant subscriptions' }}</h1>
            <p>{{ $expiring ? 'Active and trial periods ending within the next 30 days.' : 'Assign plans and manage active, expired, suspended, and cancelled periods.' }}</p>
        </div>
        <a class="button button-primary" href="{{ route('super-admin.subscriptions.create') }}">Assign subscription</a>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ $expiring ? route('super-admin.subscriptions.expiring') : route('super-admin.subscriptions.index') }}" class="toolbar-actions" data-auto-filter>
            <input class="toolbar-search" type="search" name="search" value="{{ $search }}" placeholder="Search restaurant or plan">
            @unless($expiring)
                <select class="toolbar-search" name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    @foreach(['active', 'trial', 'expired', 'suspended', 'cancelled'] as $status)
                        <option value="{{ $status }}" @selected($statusFilter === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            @endunless
        </form>
        <a class="button button-secondary" href="{{ $expiring ? route('super-admin.subscriptions.expiring') : route('super-admin.subscriptions.index') }}" data-auto-filter-reset>Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Restaurant</th>
                    <th>Plan</th>
                    <th>Price</th>
                    <th>Start</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($subscriptions as $subscription)
                    @php($effectiveStatus = $subscription->effectiveStatus())
                    <tr>
                        <td><strong>{{ $subscription->restaurant?->name ?? 'Restaurant unavailable' }}</strong><br><span class="meta-label">{{ $subscription->restaurant?->slug }}</span></td>
                        <td>{{ $subscription->plan_name }}<br><span class="meta-label">{{ $subscription->plan_code }}</span></td>
                        <td>{{ $subscription->currency }} {{ number_format((float) $subscription->price, 2) }}</td>
                        <td>{{ $subscription->starts_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td>{{ $subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td><span class="status-pill status-{{ $effectiveStatus }}">{{ ucfirst($effectiveStatus) }}</span></td>
                        <td><a class="button button-secondary" href="{{ route('super-admin.subscriptions.show', $subscription) }}">Manage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>{{ $expiring ? 'No subscriptions expiring soon' : 'No subscriptions found' }}</h3><p>{{ $expiring ? 'There are no current plans or trials ending within 30 days.' : 'Assign a subscription plan to a restaurant to begin.' }}</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="page-tools pagination-tools">{{ $subscriptions->links() }}</div>
@endsection
