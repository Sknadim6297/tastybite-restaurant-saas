@extends('layouts.super-admin')

@section('title', 'Assign Subscription')
@section('section', 'Restaurant Subscriptions')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">SUBSCRIPTION OPERATIONS</span><h1>Assign a restaurant subscription</h1><p>Choose a restaurant, plan, start date, and optional expiry override. No payment will be collected.</p></div>
    </div>
    @if($plans->isEmpty())
        <div class="notice-card"><strong>No active plans are available.</strong><p>Create or activate a subscription plan before assigning one to a restaurant.</p><a class="button button-secondary" href="{{ route('super-admin.subscription-plans.index') }}">Manage plans</a></div>
    @else
        <form method="POST" action="{{ route('super-admin.subscriptions.store') }}" class="panel-card form-stack" data-loading-form>
            @csrf
            <div class="form-grid">
                <div class="form-field">
                    <label for="restaurant_id">Restaurant</label>
                    <select id="restaurant_id" name="restaurant_id" required>
                        <option value="">Select a restaurant</option>
                        @foreach($restaurants as $restaurant)
                            <option value="{{ $restaurant->id }}" @selected((string) old('restaurant_id') === (string) $restaurant->id)>{{ $restaurant->name }} ({{ $restaurant->slug }})</option>
                        @endforeach
                    </select>
                    @error('restaurant_id')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="subscription_plan_id">Plan</label>
                    <select id="subscription_plan_id" name="subscription_plan_id" required>
                        <option value="">Select a plan</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('subscription_plan_id', request('subscription_plan_id')) === (string) $plan->id)>
                                {{ $plan->name }} — {{ $plan->currency }} {{ number_format((float) $plan->price, 2) }} / {{ $plan->billing_period === 'custom' ? ($plan->duration_months ? $plan->duration_months.' months' : $plan->duration_days.' days') : $plan->billing_period }}
                            </option>
                        @endforeach
                    </select>
                    @error('subscription_plan_id')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="starts_at">Start date</label>
                    <input id="starts_at" name="starts_at" type="date" value="{{ old('starts_at', $startDate) }}" required>
                    <span class="field-hint">Dates are interpreted in {{ config('app.timezone') }}.</span>
                    @error('starts_at')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="expiry_date">Expiry date override <span class="field-hint-inline">(optional)</span></label>
                    <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date') }}">
                    <span class="field-hint">Leave blank to calculate the expiry from plan duration.</span>
                    @error('expiry_date')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field form-field-full checkbox-field">
                    <input type="hidden" name="trial" value="0">
                    <label><input type="checkbox" name="trial" value="1" @checked((bool) old('trial'))> Assign as a trial (only available if the selected plan offers a trial)</label>
                    @error('trial')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>
            @error('subscription')<span class="field-error">{{ $message }}</span>@enderror
            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('super-admin.subscriptions.index') }}">Cancel</a>
                <button class="button button-primary" type="submit"><span class="button-label">Assign subscription</span><span class="button-spinner" aria-hidden="true"></span></button>
            </div>
        </form>
    @endif
@endsection
