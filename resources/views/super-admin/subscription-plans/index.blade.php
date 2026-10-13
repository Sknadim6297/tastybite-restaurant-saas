@extends('layouts.super-admin')

@section('title', 'Subscription Plans')
@section('section', 'Subscription Plans')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">BILLING CONFIGURATION</span>
            <h1>Subscription plans</h1>
            <p>Manage plan terms offered to TastyBite restaurants. No payment processing is enabled.</p>
        </div>
        <a class="button button-primary" href="{{ route('super-admin.subscription-plans.create') }}">Create plan</a>
    </div>

    <div class="page-tools">
        <form method="GET" action="{{ route('super-admin.subscription-plans.index') }}" class="toolbar-actions">
            <input class="toolbar-search" type="search" name="search" value="{{ $search }}" placeholder="Search plan name or code">
            <select class="toolbar-search" name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="active" @selected($statusFilter === 'active')>Active</option>
                <option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option>
            </select>
            <button class="button button-secondary" type="submit">Filter</button>
        </form>
        <a class="button button-secondary" href="{{ route('super-admin.subscription-plans.index') }}">Reset</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr><th>Plan</th><th>Price</th><th>Billing period</th><th>Trial</th><th>Subscriptions</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($plans as $plan)
                    <tr>
                        <td><strong>{{ $plan->name }}</strong><br><span class="meta-label">{{ $plan->code }}</span></td>
                        <td>{{ $plan->currency }} {{ number_format((float) $plan->price, 2) }}</td>
                        <td>{{ ucfirst($plan->billing_period) }}@if($plan->billing_period === 'custom') <span class="meta-label">{{ $plan->duration_months ? $plan->duration_months.' months' : $plan->duration_days.' days' }}</span>@endif</td>
                        <td>{{ $plan->trial_available ? $plan->trial_duration_days.' days' : 'Not available' }}</td>
                        <td>{{ $plan->subscriptions_count }}</td>
                        <td><span class="status-pill status-{{ $plan->status }}">{{ ucfirst($plan->status) }}</span></td>
                        <td>
                            <div class="action-stack">
                                <a class="button button-secondary" href="{{ route('super-admin.subscription-plans.show', $plan) }}">View</a>
                                <a class="button button-secondary" href="{{ route('super-admin.subscription-plans.edit', $plan) }}">Edit</a>
                                <form method="POST" action="{{ route('super-admin.subscription-plans.status', $plan) }}" data-loading-form data-confirm="{{ $plan->isActive() ? 'Deactivate' : 'Activate' }} {{ $plan->name }}? Existing subscriptions are not changed." data-confirm-title="{{ $plan->isActive() ? 'Deactivate plan?' : 'Activate plan?' }}" data-confirm-action="{{ $plan->isActive() ? 'Deactivate' : 'Activate' }}">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $plan->isActive() ? 'inactive' : 'active' }}">
                                    <button class="button {{ $plan->isActive() ? 'button-danger' : 'button-primary' }}" type="submit">{{ $plan->isActive() ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No plans found</h3><p>Create a plan to make it available for restaurant assignment.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="page-tools pagination-tools">{{ $plans->links() }}</div>
@endsection
