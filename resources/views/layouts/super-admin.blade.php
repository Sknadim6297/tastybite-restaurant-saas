<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $platformSettings['platform_name'] ?? 'TastyBite' }} Super Admin</title>
    @if($platformSettings['favicon_path'] ?? null)<link rel="icon" href="{{ route('super-admin.settings.branding.show', 'favicon') }}">@endif
    <link rel="stylesheet" href="{{ asset('assets/super-admin.css') }}">
</head>

<body class="admin-page">
    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar">
            <a class="brand-lockup brand-lockup-light sidebar-brand" href="{{ route('super-admin.dashboard') }}">
                @php($platformName = $platformSettings['platform_name'] ?? 'TastyBite')
                @if($platformSettings['logo_path'] ?? null)
                    <img class="platform-logo" src="{{ route('super-admin.settings.branding.show', 'logo') }}" alt="{{ $platformName }}">
                @elseif($platformName === 'TastyBite')
                    <span class="brand-name">Tasty<span>Bite</span></span>
                @else
                    <span class="brand-name">{{ $platformName }}</span>
                @endif
            </a>
            <span class="sidebar-caption">PLATFORM</span>
            <nav class="sidebar-nav" aria-label="Super Admin navigation">
                <a href="{{ route('super-admin.dashboard') }}" class="sidebar-link @if(request()->routeIs('super-admin.dashboard')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">⌂</span><span>Dashboard</span>
                </a>
                <a href="{{ route('super-admin.restaurants.index') }}" class="sidebar-link @if(request()->routeIs('super-admin.restaurants.*')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">▦</span><span>Restaurants</span>
                </a>
                <a href="{{ route('super-admin.restaurant-approvals.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.restaurant-approvals.*')])>
                    <span class="nav-icon" aria-hidden="true">✓</span><span>Restaurant Approvals</span>
                </a>
                <a href="{{ route('super-admin.restaurant-owners.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.restaurant-owners.*')])>
                    <span class="nav-icon" aria-hidden="true">♙</span><span>Restaurant Owners</span>
                </a>
                <a href="{{ route('super-admin.subscription-plans.index') }}" class="sidebar-link @if(request()->routeIs('super-admin.subscription-plans.*')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">◇</span><span>Subscription Plans</span>
                </a>
                <a href="{{ route('super-admin.features.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.features.*')])>
                    <span class="nav-icon" aria-hidden="true">⚙</span><span>Feature Management</span>
                </a>
                <a href="{{ route('super-admin.support-tickets.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.support-tickets.*')])>
                    <span class="nav-icon" aria-hidden="true">✉</span><span>Support Tickets</span>
                </a>
                <a href="{{ route('super-admin.announcements.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.announcements.*')])>
                    <span class="nav-icon" aria-hidden="true">▣</span><span>Announcements</span>
                </a>
                <a href="{{ route('super-admin.subscriptions.index') }}" class="sidebar-link @if(request()->routeIs('super-admin.subscriptions.index', 'super-admin.subscriptions.create', 'super-admin.subscriptions.show')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">↻</span><span>Restaurant Subscriptions</span>
                </a>
                <a href="{{ route('super-admin.subscriptions.expiring') }}" class="sidebar-link @if(request()->routeIs('super-admin.subscriptions.expiring')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">◷</span><span>Expiring Soon</span>
                </a>
                <a href="{{ route('super-admin.subscriptions.history') }}" class="sidebar-link @if(request()->routeIs('super-admin.subscriptions.history')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">≡</span><span>Subscription History</span>
                </a>
                <a href="{{ route('super-admin.payments.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.payments.*')])>
                    <span class="nav-icon" aria-hidden="true">₹</span><span>Payments</span>
                </a>
                <a href="{{ route('super-admin.invoices.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.invoices.*')])>
                    <span class="nav-icon" aria-hidden="true">▧</span><span>Invoices &amp; Billing</span>
                </a>
                <a href="{{ route('super-admin.analytics.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.analytics.*')])>
                    <span class="nav-icon" aria-hidden="true">▤</span><span>Analytics</span>
                </a>
                <a href="{{ route('super-admin.reports.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.reports.*')])><span class="nav-icon" aria-hidden="true">▤</span><span>Reports</span></a>
                <a href="{{ route('super-admin.notifications.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.notifications.*')])><span class="nav-icon" aria-hidden="true">♧</span><span>Notifications</span></a>
                <a href="{{ route('super-admin.activity-logs.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.activity-logs.*')])><span class="nav-icon" aria-hidden="true">≡</span><span>Activity Logs</span></a>
                <a href="{{ route('super-admin.settings.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('super-admin.settings.*')])><span class="nav-icon" aria-hidden="true">⚙</span><span>Settings</span></a>
                <form method="POST" action="{{ route('super-admin.logout') }}" data-confirm="Are you sure you want to sign out?">
                    @csrf
                    <button type="submit" class="sidebar-link sidebar-logout-button"><span class="nav-icon" aria-hidden="true">⇥</span><span>Logout</span></button>
                </form>
            </nav>
            <div class="sidebar-bottom">
                <span class="sidebar-status-dot" aria-hidden="true"></span>
                <span>Platform control</span>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <button class="icon-button sidebar-toggle" type="button" data-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open navigation">
                    <span aria-hidden="true">☰</span>
                </button>
                <div class="breadcrumb"><span>Super Admin</span><span aria-hidden="true">/</span><strong>@yield('section', 'Dashboard')</strong></div>
                <details class="notification-menu" data-notification-menu data-feed-url="{{ route('super-admin.notifications.feed') }}" data-read-all-url="{{ route('super-admin.notifications.read-all') }}" data-read-url-template="{{ route('super-admin.notifications.read', ['notification' => '__ID__']) }}" data-polling-interval="{{ $notificationSettings['polling_interval'] ?? 60 }}">
                    <summary class="icon-button notification-trigger" aria-label="Notifications">
                        <span aria-hidden="true">♧</span><span class="notification-count" data-notification-count aria-live="polite">0</span>
                    </summary>
                    <div class="notification-dropdown">
                        <div class="notification-dropdown-heading"><strong>Notifications</strong><a href="{{ route('super-admin.notifications.index') }}">View all</a></div>
                        <div class="notification-preview-list" data-notification-list aria-live="polite"><p class="notification-empty">Loading notifications…</p></div>
                        <button type="button" class="notification-read-all" data-notification-read-all>Mark all as read</button>
                    </div>
                </details>
                <details class="profile-menu">
                    <summary class="profile-trigger">
                        <span class="avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($superAdmin->name, 0, 1)) }}</span>
                        <span class="profile-trigger-name">{{ $superAdmin->name }}</span>
                        <span class="profile-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="profile-dropdown">
                        <span class="dropdown-label">SIGNED IN AS</span>
                        <strong>{{ $superAdmin->name }}</strong>
                        <span class="dropdown-email">{{ $superAdmin->email }}</span>
                        <a href="{{ route('super-admin.profile') }}">Account profile</a>
                        <form method="POST" action="{{ route('super-admin.logout') }}" data-confirm="Are you sure you want to sign out?">
                            @csrf
                            <button type="submit" class="dropdown-logout">Sign out</button>
                        </form>
                    </div>
                </details>
            </header>
            <main class="admin-content">
                @yield('content')
            </main>
        </div>
    </div>

    <dialog class="confirm-dialog" data-confirm-dialog aria-labelledby="confirm-title" aria-describedby="confirm-message">
        <div class="confirm-icon" aria-hidden="true">↪</div>
        <h2 id="confirm-title">Confirm action</h2>
        <p id="confirm-message" data-confirm-message>Are you sure you want to sign out?</p>
        <div class="confirm-actions">
            <button class="button button-secondary" type="button" data-confirm-cancel>Stay here</button>
            <button class="button button-danger" type="button" data-confirm-accept>Confirm</button>
        </div>
    </dialog>

    @include('components.super-admin.toasts')
    <script src="{{ asset('assets/super-admin.js') }}" defer></script>
</body>

</html>