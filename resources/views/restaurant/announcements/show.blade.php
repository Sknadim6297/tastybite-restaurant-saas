@extends('layouts.restaurant-admin')

@section('title', $announcement->title)
@section('section', 'Announcements')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">PLATFORM ANNOUNCEMENT</span><h1>{{ $announcement->title }}</h1><p>Published {{ $announcement->published_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}.</p></div>
        <a class="button button-secondary" href="{{ route('restaurant.announcements.index') }}">Back to announcements</a>
    </div>
    <div class="form-card">
        <div class="detail-grid">
            <div class="detail-item"><span>Message</span><strong style="white-space:pre-wrap;">{{ $announcement->body }}</strong></div>
            <div class="detail-item"><span>Expires</span><strong>{{ $announcement->expires_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? 'No expiry' }}</strong></div>
            <div class="detail-item"><span>Read status</span><strong>{{ $announcement->reads->isNotEmpty() ? 'Read' : 'Unread' }}</strong></div>
        </div>
        @if ($announcement->reads->isEmpty())
            <form method="POST" action="{{ route('restaurant.announcements.read', $announcement) }}" style="margin-top:24px;">
                @csrf
                <button class="button button-primary" type="submit">Mark as read</button>
            </form>
        @endif
    </div>
@endsection
