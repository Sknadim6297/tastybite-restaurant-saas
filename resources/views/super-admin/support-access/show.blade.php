@extends('layouts.super-admin')

@section('title', 'Support Access Request')
@section('section', 'Support Access')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">REQUEST #{{ $supportRequest->id }}</span><h1>{{ $supportRequest->restaurant->name }}</h1><p>Submitted by {{ $supportRequest->requester->name }} · {{ $supportRequest->created_at->format('M j, Y H:i') }}</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.support-access.index') }}">Back to requests</a>
    </div>

    @if ($supportRequest->requested_scope === \App\Models\SupportAccessRequest::RESTAURANT_DASHBOARD_MENU_MANAGE)
        <div class="notice-card" role="note"><strong>Support Access requests remain a separate approval record.</strong><p>This request does not start or authorize direct dashboard access. Use Restaurants → Go to Dashboard for the direct, temporary Super Admin context.</p></div>
    @else
        <div class="notice-card" role="note"><strong>Approval records an authorization decision only.</strong><p>This scope does not open restaurant data or grant a restaurant session.</p></div>
    @endif
    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Request status</span><strong>{{ ucfirst($supportRequest->status) }}</strong></div>
            <div class="detail-item"><span>Requested scope</span><strong>{{ ucfirst($supportRequest->requested_scope) }}</strong></div>
            <div class="detail-item"><span>Requested duration</span><strong>{{ $supportRequest->requested_duration_minutes }} minutes</strong></div>
            <div class="detail-item"><span>Requester</span><strong>{{ $supportRequest->requester->name }} · {{ $supportRequest->requester->email }}</strong></div>
            <div class="detail-item full-span"><span>Business justification</span><strong style="white-space:pre-wrap;">{{ $supportRequest->reason }}</strong></div>
            @if ($supportRequest->reviewer)
                <div class="detail-item"><span>Reviewed by</span><strong>{{ $supportRequest->reviewer->name }}</strong></div>
                <div class="detail-item"><span>Reviewed at</span><strong>{{ $supportRequest->reviewed_at?->format('M j, Y H:i') }}</strong></div>
                <div class="detail-item full-span"><span>Review reason</span><strong style="white-space:pre-wrap;">{{ $supportRequest->review_reason }}</strong></div>
            @endif
            @if ($supportRequest->access_started_at)
                <div class="detail-item"><span>Access started</span><strong>{{ $supportRequest->access_started_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</strong></div>
                <div class="detail-item"><span>Access expires</span><strong>{{ $supportRequest->access_expires_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? 'Not available' }}</strong></div>
                @if ($supportRequest->access_ended_at)
                    <div class="detail-item"><span>Access ended</span><strong>{{ $supportRequest->access_ended_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</strong></div>
                @elseif ($supportRequest->access_revoked_at)
                    <div class="detail-item"><span>Access revoked</span><strong>{{ $supportRequest->access_revoked_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</strong></div>
                @endif
            @endif
        </div>
    </div>

    @if ($supportRequest->requested_scope === \App\Models\SupportAccessRequest::RESTAURANT_DASHBOARD_MENU_MANAGE && $supportRequest->access_started_at && $supportRequest->access_ended_at === null && $supportRequest->access_revoked_at === null)
        @if ($supportRequest->requester_admin_id !== $currentAdmin->id)
            <div class="page-tools" style="margin-top:20px; justify-content:flex-end;">
                <form method="POST" action="{{ route('super-admin.support-access.revoke', $supportRequest) }}" data-confirm="Immediately revoke this restaurant support session?">
                    @csrf
                    <input type="hidden" name="reason" value="Revoked by {{ $currentAdmin->name }} from the support-access request details.">
                    <button class="button button-danger" type="submit">Revoke active access</button>
                </form>
            </div>
        @endif
    @endif

    @if ($supportRequest->status === 'pending')
        @if ($supportRequest->requester_admin_id === $currentAdmin->id)
            <div class="page-tools" style="margin-top:20px; justify-content:flex-end;">
                <form method="POST" action="{{ route('super-admin.support-access.cancel', $supportRequest) }}" data-confirm="Cancel this pending support-access request?" data-confirm-title="Cancel request?" data-confirm-action="Cancel">
                    @csrf
                    <button class="button button-secondary" type="submit">Cancel my request</button>
                </form>
            </div>
            <div class="field-hint">A requester cannot approve their own request. Another active Super Admin must review it.</div>
        @else
            <div class="section-heading" style="margin-top:28px;"><div><h2>Independent decision</h2><p>Record approval or denial with a reason. Neither decision opens tenant access.</p></div></div>
            <div class="panel-card">
                <div class="form-grid">
                <form method="POST" action="{{ route('super-admin.support-access.decide', $supportRequest) }}" class="form-stack" data-loading-form data-confirm="Approve this request record? This will not grant tenant access." data-confirm-title="Approve request record?" data-confirm-action="Approve">
                    @csrf
                    <input type="hidden" name="decision" value="approved">
                    <label for="approval_reason">Approval reason</label>
                    <textarea id="approval_reason" name="reason" rows="3" required minlength="10" maxlength="2000">{{ old('reason') }}</textarea>
                    @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                    @error('decision')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-primary" type="submit">Approve request record</button>
                </form>
                <form method="POST" action="{{ route('super-admin.support-access.decide', $supportRequest) }}" class="form-stack" data-loading-form data-confirm="Deny this support-access request?" data-confirm-title="Deny request?" data-confirm-action="Deny">
                    @csrf
                    <input type="hidden" name="decision" value="denied">
                    <label for="denial_reason">Denial reason</label>
                    <textarea id="denial_reason" name="reason" rows="3" required minlength="10" maxlength="2000">{{ old('reason') }}</textarea>
                    @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-danger" type="submit">Deny request</button>
                </form>
                </div>
            </div>
        @endif
    @endif

    <div class="section-heading" style="margin-top:32px;"><div><h2>Immutable request history</h2><p>Every submission, review and cancellation is retained.</p></div></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Event</th><th>Administrator</th><th>From / to</th><th>Reason</th></tr></thead>
            <tbody>
                @forelse ($supportRequest->events as $event)
                    <tr><td>{{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y H:i:s') }}</td><td>{{ ucfirst($event->action) }}</td><td>{{ $event->actor?->name ?? 'Unavailable administrator' }}</td><td>{{ $event->previous_status ? ucfirst($event->previous_status).' → ' : '' }}{{ ucfirst($event->new_status) }}</td><td>{{ $event->reason ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><h3>No request history</h3><p>No events have been recorded.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
