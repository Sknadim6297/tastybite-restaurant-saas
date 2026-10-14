@extends('layouts.super-admin')

@section('title', 'Activity Log Details')
@section('section', 'Activity Logs')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">READ-ONLY AUDIT RECORD</span><h1>Activity #{{ $log->id }}</h1><p>{{ str($log->action)->replace('_', ' ')->title() }} · {{ $log->created_at?->format(config('app.date_format', 'M j, Y').' · g:i:s A') }}</p></div><a class="button button-secondary" href="{{ route('super-admin.activity-logs.index') }}">Back to logs</a></div>
    <section class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Actor</span><strong>{{ $log->actor?->name ?? 'System / unavailable' }} ({{ $log->actor_type }})</strong></div>
            <div class="detail-item"><span>Module</span><strong>{{ $module }}</strong></div>
            <div class="detail-item"><span>Action</span><strong>{{ $log->action }}</strong></div>
            <div class="detail-item"><span>Affected record</span><strong>{{ $log->target_type }} {{ $log->target_id ? '#'.$log->target_id : '' }}</strong></div>
            <div class="detail-item"><span>Restaurant</span><strong>{{ $log->restaurant?->name ?? 'Not associated' }}</strong></div>
            <div class="detail-item"><span>IP address</span><strong>{{ $log->ip_address ?? 'Not recorded' }}</strong></div>
        </div>
        <div class="section-heading"><div><h2>Recorded details</h2><p>Sensitive credential-like fields are omitted.</p></div></div>
        @include('super-admin.activity-logs._details', ['details' => $details])
        @if($relatedUrl)<a class="button button-secondary" href="{{ $relatedUrl }}">Open related record</a>@endif
    </section>
@endsection
