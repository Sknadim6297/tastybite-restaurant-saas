@extends('layouts.super-admin')

@section('title', 'Security Center')
@section('section', 'Security Center')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">SECURITY POSTURE</span><h1>Security Center</h1><p>Read-only authentication, session, account and security-event monitoring.</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.settings.index', ['tab' => 'security']) }}">Manage your password</a>
    </div>

    <section class="summary-row report-summary" aria-label="Security overview">
        <article class="summary-tile"><span>Active Super Admins</span><strong>{{ number_format($adminCounts['active']) }}</strong></article>
        <article class="summary-tile"><span>Inactive Super Admins</span><strong>{{ number_format($adminCounts['inactive']) }}</strong></article>
        <article class="summary-tile"><span>Failed login attempts · 24h</span><strong>{{ number_format($activity['failed_logins']) }}</strong><small>{{ number_format($activity['failed_admin_logins']) }} Super Admin attempts</small></article>
        <article class="summary-tile"><span>Successful admin sign-ins · 24h</span><strong>{{ number_format($activity['successful_admin_logins']) }}</strong></article>
        <article class="summary-tile"><span>Successful owner sign-ins · 24h</span><strong>{{ number_format($activity['successful_owner_logins']) }}</strong></article>
    </section>

    <div class="section-heading"><div><h2>Session and recovery controls</h2><p>Effective runtime configuration; sensitive configuration values are not shown.</p></div></div>
    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Session driver</span><strong>{{ $session['driver'] }}</strong></div>
            <div class="detail-item"><span>Session lifetime</span><strong>{{ $session['lifetime_minutes'] }} minutes{{ $session['expires_on_close'] ? ' and on browser close' : '' }}</strong></div>
            <div class="detail-item"><span>Secure cookie</span><strong>{{ $session['secure_cookie'] ? 'Enabled for this request' : 'Not enforced for this request' }}</strong></div>
            <div class="detail-item"><span>HttpOnly cookie</span><strong>{{ $session['http_only'] ? 'Enabled' : 'Disabled' }}</strong></div>
            <div class="detail-item"><span>SameSite</span><strong>{{ $session['same_site'] }}</strong></div>
            <div class="detail-item"><span>Super Admin login throttling</span><strong>{{ $loginLimit }} attempts per minute per email/IP key</strong></div>
            <div class="detail-item"><span>Password reset token expiry</span><strong>{{ $resetTokenTtl }} minutes</strong></div>
            <div class="detail-item"><span>Password reset throttle</span><strong>{{ $resetThrottleSeconds }} seconds</strong></div>
            <div class="detail-item"><span>Configured mail transport</span><strong>{{ $mailDriver }} — {{ $mailStatus }}</strong></div>
            <div class="detail-item"><span>Database driver</span><strong>{{ $databaseDriver }}</strong></div>
        </div>
        <div class="notice-card" role="note" style="margin-top:20px;"><strong>Monitoring scope</strong><p>Login events are recorded only after a syntactically valid email/password submission reaches the authentication check. Invalid-form and rate-limited requests are not counted. Failed login records store a keyed, non-reversible account fingerprint rather than the submitted email or password.</p></div>
    </div>

    <div class="section-heading" style="margin-top:32px;"><div><h2>Administrator accounts</h2><p>Account state only; the current schema does not retain per-admin last-login timestamps.</p></div></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($admins as $admin)
                    <tr><td>{{ $admin->name }}</td><td>{{ $admin->email }}</td><td><span class="status-pill status-{{ $admin->is_active ? 'active' : 'inactive' }}">{{ $admin->is_active ? 'Active' : 'Inactive' }}</span></td></tr>
                @empty
                    <tr><td colspan="3"><div class="empty-state"><h3>No Super Admin accounts</h3><p>No administrator records are available.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section-heading" style="margin-top:32px;"><div><h2>Recent authentication events</h2><p>Latest recorded sign-ins, failed attempts and password changes.</p></div></div>
    <div class="table-shell">
        <table class="data-table">
            <thead><tr><th>Event</th><th>Actor</th><th>Address</th><th>Time</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr><td>{{ str($event->action)->replace('_', ' ')->title() }}</td><td>{{ $event->actor?->name ?? ucfirst($event->actor_type) }}</td><td>{{ $event->ip_address ?? 'Not recorded' }}</td><td>{{ $event->created_at->timezone(config('app.timezone'))->format('M j, Y H:i:s') }}</td></tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state"><h3>No authentication events recorded</h3><p>Events will appear as supported authentication actions occur.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
