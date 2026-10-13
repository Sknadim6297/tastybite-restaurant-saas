@extends('layouts.super-admin')

@section('title', 'Support Access Request')
@section('section', 'Support Access')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">REQUEST #{{ $supportRequest->id }}</span><h1>{{ $supportRequest->restaurant->name }}</h1><p>Submitted by {{ $supportRequest->requester->name }} · {{ $supportRequest->created_at->format('M j, Y H:i') }}</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.support-access.index') }}">Back to requests</a>
    </div>

    <div class="notice-card" role="note"><strong>Approval does not grant system access.</strong><p>No impersonation token, owner session, guard switch, or tenant data access is issued by this workflow. The application has no support-access execution mechanism.</p></div>
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
        </div>
    </div>

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
