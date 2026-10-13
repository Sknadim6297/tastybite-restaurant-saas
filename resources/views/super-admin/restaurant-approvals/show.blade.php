@extends('layouts.super-admin')

@section('title', 'Review '.$restaurant->name)
@section('section', 'Restaurant Approvals')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">RESTAURANT REVIEW</span>
            <h1>{{ $restaurant->name }}</h1>
            <p>Review the provisioned restaurant and record an approval decision.</p>
        </div>
        <a class="button button-secondary" href="{{ route('super-admin.restaurant-approvals.index') }}">Back to approvals</a>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ ucfirst($restaurant->status) }}</strong><span>Account status</span></div>
        <div class="summary-tile"><strong>{{ ucfirst(str_replace('_', ' ', $restaurant->approval_status)) }}</strong><span>Approval status</span></div>
        <div class="summary-tile"><strong>{{ $restaurant->approvalHistory->count() }}</strong><span>Review events</span></div>
    </div>

    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Restaurant</span><strong>{{ $restaurant->name }}</strong></div>
            <div class="detail-item"><span>Slug</span><strong>{{ $restaurant->slug }}</strong></div>
            <div class="detail-item"><span>Email</span><strong>{{ $restaurant->email }}</strong></div>
            <div class="detail-item"><span>Phone</span><strong>{{ $restaurant->phone ?? '—' }}</strong></div>
            <div class="detail-item full-span"><span>Address</span><strong>{{ $restaurant->address ?? '—' }}</strong></div>
            @foreach ($restaurant->owners as $owner)
                <div class="detail-item"><span>Owner</span><strong>{{ $owner->name }} ({{ ucfirst($owner->status) }})</strong></div>
                <div class="detail-item"><span>Owner email</span><strong>{{ $owner->email }}</strong></div>
            @endforeach
            <div class="detail-item"><span>Created</span><strong>{{ $restaurant->created_at->format('F j, Y H:i') }}</strong></div>
        </div>
        <div class="notice-card" role="note" style="margin-top:20px;">
            <strong>Submitted documents</strong>
            <p>The existing restaurant provisioning form does not collect verification documents. No document upload or verification is represented in this review.</p>
        </div>
    </div>

    @if (in_array($restaurant->approval_status, ['pending', 'information_requested'], true))
        <div class="section-heading" style="margin-top:28px;">
            <div><h2>Record decision</h2><p>Decisions are stored in the permanent approval history and platform audit log.</p></div>
        </div>
        <div class="panel-card">
            <div class="form-grid">
                <form method="POST" action="{{ route('super-admin.restaurant-approvals.decide', $restaurant) }}" class="form-stack">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="decision" value="approved">
                    <h3>Approve restaurant</h3>
                    <label for="approval_reason">Optional note</label>
                    <textarea id="approval_reason" name="reason" rows="2" maxlength="2000">{{ old('reason') }}</textarea>
                    @error('decision')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-primary" type="submit" data-loading-form>Approve</button>
                </form>

                <form method="POST" action="{{ route('super-admin.restaurant-approvals.decide', $restaurant) }}" class="form-stack">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="decision" value="information_requested">
                    <h3>Request more information</h3>
                    <label for="information_reason">Note for the owner</label>
                    <textarea id="information_reason" name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
                    @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-secondary" type="submit" data-loading-form>Request information</button>
                </form>

                <form method="POST" action="{{ route('super-admin.restaurant-approvals.decide', $restaurant) }}" class="form-stack">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="decision" value="rejected">
                    <h3>Reject restaurant</h3>
                    <label for="rejection_reason">Reason for rejection</label>
                    <textarea id="rejection_reason" name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
                    @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-danger" type="submit" data-loading-form data-confirm="Reject {{ $restaurant->name }}? Operational access will remain blocked." data-confirm-title="Reject restaurant?" data-confirm-action="Reject">Reject</button>
                </form>

                @if ($restaurant->approval_status === 'information_requested')
                    <form method="POST" action="{{ route('super-admin.restaurant-approvals.decide', $restaurant) }}" class="form-stack">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="decision" value="pending">
                        <h3>Return to review queue</h3>
                        <label for="queue_reason">Internal review note</label>
                        <textarea id="queue_reason" name="reason" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
                        <button class="button button-secondary" type="submit" data-loading-form>Return to pending</button>
                    </form>
                @endif
            </div>
        </div>
    @else
        <div class="notice-card" role="status" style="margin-top:24px;">
            <strong>This decision is final in the current workflow.</strong>
            <p>The restaurant is {{ $restaurant->approval_status }}. The workflow does not support reopening approved or rejected decisions.</p>
        </div>
    @endif

    <div class="section-heading" style="margin-top:32px;">
        <div><h2>Approval history</h2><p>Chronological decisions and notes, newest first.</p></div>
    </div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Time</th><th>From</th><th>To</th><th>Administrator</th><th>Reason / note</th></tr></thead>
            <tbody>
                @forelse ($restaurant->approvalHistory as $entry)
                    <tr>
                        <td>{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td>
                        <td>{{ $entry->previous_status ? ucfirst(str_replace('_', ' ', $entry->previous_status)) : 'Created' }}</td>
                        <td><span class="status-pill status-{{ $entry->new_status === 'information_requested' ? 'pending' : $entry->new_status }}">{{ ucfirst(str_replace('_', ' ', $entry->new_status)) }}</span></td>
                        <td>{{ $entry->actor?->name ?? 'System' }}</td>
                        <td>{{ $entry->reason ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><h3>No review history</h3><p>No approval decisions have been recorded for this restaurant.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
