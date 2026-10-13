@extends('layouts.super-admin')

@section('title', 'Notifications')
@section('section', 'Notifications')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">PLATFORM UPDATES</span><h1>Notifications</h1><p>New restaurants, subscription lifecycle events, payments and expiry alerts.</p></div>
        <form method="POST" action="{{ route('super-admin.notifications.read-all') }}">@csrf<button class="button button-secondary" type="submit" @disabled($unreadCount === 0)>Mark all as read</button></form>
    </div>
    <div class="notification-inbox">
        @forelse($notifications as $notification)
            <article class="notification-inbox-item @if($notification->read_at === null) is-unread @endif">
                <div class="notification-inbox-marker" aria-hidden="true"></div>
                <div class="notification-inbox-content"><div class="notification-inbox-title"><h2>{{ $notification->title }}</h2><time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->format(config('app.date_format', 'M j, Y').' · g:i A') }}</time></div><p>{{ $notification->description }}</p>
                    <div class="action-stack">
                        @if($notification->url)<a class="button button-secondary button-small" href="{{ $notification->url }}">Open details</a>@endif
                        @if($notification->read_at === null)<form method="POST" action="{{ route('super-admin.notifications.read', $notification) }}">@csrf<button class="button button-secondary button-small" type="submit">Mark as read</button></form>@else<span class="meta-label">Read</span>@endif
                    </div>
                </div>
            </article>
        @empty
            <div class="empty-state"><h3>No notifications yet</h3><p>Platform events and subscription expiry reminders will appear here.</p></div>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $notifications->links() }}</div>
@endsection
