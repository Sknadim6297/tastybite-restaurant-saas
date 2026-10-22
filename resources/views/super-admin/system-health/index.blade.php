@extends('layouts.super-admin')

@section('title', 'System Health')
@section('section', 'System Health')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">OPERATIONS MONITORING</span><h1>System Health</h1><p>Read-only runtime configuration and dependency checks. This page does not modify the database or run migrations.</p></div>
    </div>

    <section class="summary-row report-summary" aria-label="Application health status">
        <article class="summary-tile"><span>Application environment</span><strong>{{ $environment }}</strong><small>{{ $debugEnabled ? 'Debug mode enabled' : 'Debug mode disabled' }}</small></article>
        <article class="summary-tile"><span>Database</span><strong>{{ \App\Support\StatusPresenter::label($database['status'] ?? null) }}</strong><small>{{ $database['driver'] }}</small></article>
        <article class="summary-tile"><span>Pending migrations</span><strong>{{ count($database['pendingMigrations']) }}</strong><small>No migrations are executed from this page</small></article>
        <article class="summary-tile"><span>Private storage</span><strong>{{ $storage['writable'] ? 'Writable' : 'Not ready' }}</strong><small>Local private disk</small></article>
    </section>

    <div class="notice-card" role="{{ $database['status'] === 'unavailable' ? 'alert' : 'status' }}">
        <strong>Database check: {{ \App\Support\StatusPresenter::label($database['status'] ?? null) }}</strong>
        <p>{{ $database['note'] }}</p>
    </div>

    @if ($database['pendingMigrations'] !== [])
        <div class="section-heading"><div><h2>Pending migration files</h2><p>Review and apply only through your normal approved deployment process.</p></div></div>
        <div class="form-card">
            <ul>
                @foreach ($database['pendingMigrations'] as $migration)
                    <li>{{ $migration }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="section-heading" style="margin-top:32px;"><div><h2>Configured services</h2><p>Driver names only; credentials and connection URLs are never displayed.</p></div></div>
    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Cache driver</span><strong>{{ $cacheDriver }}</strong></div>
            <div class="detail-item"><span>Queue driver</span><strong>{{ $queueDriver }}</strong></div>
            <div class="detail-item"><span>Mail driver</span><strong>{{ $mailDriver }} — delivery is not verified by this check</strong></div>
            <div class="detail-item"><span>Application key</span><strong>{{ $applicationKeyConfigured ? 'Configured (value hidden)' : 'Missing' }}</strong></div>
            <div class="detail-item"><span>Local private storage</span><strong>{{ $storage['writable'] ? 'Directory exists and is writable' : 'Directory missing or not writable' }}</strong></div>
            <div class="detail-item"><span>Scheduler execution</span><strong>Not observable from a web request; server cron/worker status must be checked separately.</strong></div>
        </div>
        <div class="notice-card" role="note" style="margin-top:20px;"><strong>Operational limits</strong><p>Cache/queue backend connectivity, mail delivery, scheduler invocation and queue-worker liveness are not probed here. Driver configuration alone is not proof that those services are running.</p></div>
    </div>
@endsection
