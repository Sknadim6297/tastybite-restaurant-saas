@extends('layouts.super-admin')

@section('title', 'Feature Management')
@section('section', 'Feature Management')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">SUBSCRIPTION CONFIGURATION</span>
            <h1>Feature management</h1>
            <p>Control implemented platform features and their plan access. Planned modules remain locked until they are built.</p>
        </div>
    </div>

    @if($plans->isEmpty())
        <div class="empty-state panel-card"><h3>No subscription plans yet</h3><p>Create a plan before assigning feature access.</p></div>
    @endif

    <section class="feature-registry" aria-label="SaaS feature registry">
        @foreach($features as $feature)
            @php($planAccess = $feature->plans->keyBy('id'))
            <article class="panel-card feature-card">
                <div class="feature-card-heading">
                    <div>
                        <span class="meta-label">{{ $feature->code }}</span>
                        <h2>{{ $feature->name }}</h2>
                        <p>{{ $feature->description }}</p>
                    </div>
                    <span class="status-pill {{ $feature->implemented ? ($feature->is_enabled ? 'status-active' : 'status-inactive') : 'status-disabled' }}">
                        {{ $feature->implemented ? ($feature->is_enabled ? 'Implemented · enabled' : 'Implemented · disabled') : 'Not implemented' }}
                    </span>
                </div>

                @if($feature->implemented)
                    <form method="POST" action="{{ route('super-admin.features.update', $feature) }}" class="feature-form" data-loading-form>
                        @csrf
                        @method('PUT')
                        <div class="feature-global-toggle">
                            <input type="hidden" name="enabled" value="0">
                            <label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $feature->is_enabled))> Enable this feature platform-wide</label>
                            @error('enabled')<span class="field-error">{{ $message }}</span>@enderror
                        </div>

                        @if($plans->isNotEmpty())
                            <div class="table-shell">
                                <table class="data-table feature-plan-table">
                                    <thead><tr><th>Subscription plan</th><th>Plan access</th><th>Limit</th></tr></thead>
                                    <tbody>
                                        @foreach($plans as $plan)
                                            @php($mapping = $planAccess->get($plan->id))
                                            <tr>
                                                <td><strong>{{ $plan->name }}</strong><br><span class="meta-label">{{ $plan->code }}</span></td>
                                                <td>
                                                    <input type="hidden" name="plans[{{ $plan->id }}][enabled]" value="0">
                                                    <label class="feature-access-toggle">
                                                        <input type="checkbox" name="plans[{{ $plan->id }}][enabled]" value="1" @checked(old("plans.{$plan->id}.enabled", $mapping?->pivot?->is_enabled ?? false))>
                                                        <span>{{ $plan->name }} access</span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <label class="visually-hidden" for="feature-{{ $feature->id }}-plan-{{ $plan->id }}-limit">{{ $plan->name }} limit (leave blank for unlimited)</label>
                                                    <input id="feature-{{ $feature->id }}-plan-{{ $plan->id }}-limit" class="feature-limit-input" type="number" min="0" max="2147483647" name="plans[{{ $plan->id }}][limit]" value="{{ old("plans.{$plan->id}.limit", $mapping?->pivot?->limit_value) }}" placeholder="No limit">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @error('plans')<span class="field-error">{{ $message }}</span>@enderror
                        <div class="form-actions"><button class="button button-primary" type="submit">Save feature access</button></div>
                    </form>
                @else
                    <p class="feature-locked-note">This feature cannot be enabled until its application module is implemented and protected by server-side authorization.</p>
                @endif
            </article>
        @endforeach
    </section>

    <section class="feature-history panel-card" aria-labelledby="feature-history-title">
        <div class="section-heading"><div><h2 id="feature-history-title">Feature access history</h2><p>Recent changes are retained in the platform audit log.</p></div></div>
        <div class="table-shell">
            <table class="data-table">
                <thead><tr><th>Feature</th><th>Administrator</th><th>Changed</th><th>Details</th></tr></thead>
                <tbody>
                    @forelse($history as $entry)
                        <tr>
                            <td>{{ data_get($entry->details, 'feature_code', 'Feature') }}</td>
                            <td>{{ $entry->actor?->name ?? 'Former administrator' }}</td>
                            <td>{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td>
                            <td><a class="button button-secondary" href="{{ route('super-admin.activity-logs.show', $entry) }}">View audit record</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><h3>No feature changes yet</h3><p>Saved feature access changes will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
