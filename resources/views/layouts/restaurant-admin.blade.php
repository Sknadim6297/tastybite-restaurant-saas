<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · TastyBite Restaurant</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('assets/super-admin.css') }}?v={{ filemtime(public_path('assets/super-admin.css')) }}">
</head>

<body class="admin-page">
    @php
        $adminAccessAdmin = request()->attributes->get('restaurant_admin_access_admin');
        $adminAccessRestaurant = request()->attributes->get('restaurant_admin_access_restaurant');
        $adminMode = $adminAccessRestaurant !== null && $adminAccessAdmin !== null;
    @endphp
    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar">
            <a class="brand-lockup brand-lockup-light sidebar-brand" href="{{ route('restaurant.dashboard') }}">
                <span class="brand-name">Tasty<span>Bite</span></span>
            </a>
            <span class="sidebar-caption">OPERATIONS</span>
            <nav class="sidebar-nav" aria-label="Restaurant admin navigation">
                <a href="{{ route('restaurant.dashboard') }}" class="sidebar-link @if(request()->routeIs('restaurant.dashboard')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true">⌂</span><span>Dashboard</span>
                </a>
                <a href="{{ route('pos.index') }}" class="sidebar-link @if(request()->routeIs('pos.index')) is-active @endif"><span class="nav-icon" aria-hidden="true">▣</span><span>POS Billing</span></a>
                <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">◫</span><span>Orders</span><span class="nav-soon">Soon</span></span>
                <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">♨</span><span>Kitchen / KOT</span><span class="nav-soon">Soon</span></span>
                <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">▤</span><span>Table Management</span><span class="nav-soon">Soon</span></span>
                <a href="{{ route('restaurant.menu.categories.index') }}" class="sidebar-link @if(request()->routeIs('restaurant.menu.categories.*', 'restaurant.menu.products.*')) is-active @endif"><span class="nav-icon" aria-hidden="true">☰</span><span>Menu Management</span></a>
                <a href="{{ route('restaurant.menu.categories.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.menu.categories.*')) is-active @endif"><span class="nav-icon" aria-hidden="true">◦</span><span>Categories</span></a>
                <a href="{{ route('restaurant.menu.products.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.menu.products.*')) is-active @endif"><span class="nav-icon" aria-hidden="true">◦</span><span>Products</span></a>
                @unless ($adminMode)
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">◫</span><span>Inventory</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">◧</span><span>Purchases</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">♙</span><span>Staff Management</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">◍</span><span>Customers</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">￥</span><span>Expenses</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">▤</span><span>Reports</span><span class="nav-soon">Soon</span></span>
                    <a href="{{ route('restaurant.subscription') }}" class="sidebar-link @if(request()->routeIs('restaurant.subscription')) is-active @endif"><span class="nav-icon" aria-hidden="true">◇</span><span>Subscription</span></a>
                    <a href="{{ route('restaurant.support.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.support.*')])><span class="nav-icon" aria-hidden="true">✉</span><span>Support Tickets</span></a>
                    <a href="{{ route('restaurant.announcements.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.announcements.*')])><span class="nav-icon" aria-hidden="true">▣</span><span>Announcements</span></a>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true">⚙</span><span>Settings</span><span class="nav-soon">Soon</span></span>
                    <form method="POST" action="{{ route('restaurant.logout') }}" data-confirm="Are you sure you want to sign out?">
                        @csrf
                        <button type="submit" class="sidebar-link sidebar-logout-button"><span class="nav-icon" aria-hidden="true">⇥</span><span>Logout</span></button>
                    </form>
                @endunless
            </nav>
            <div class="sidebar-bottom">
                <span class="sidebar-status-dot" aria-hidden="true"></span>
                <span>{{ $restaurant->name ?? 'Restaurant' }}</span>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <button class="icon-button sidebar-toggle" type="button" data-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open navigation">
                    <span aria-hidden="true">☰</span>
                </button>
                <div class="breadcrumb"><span>Restaurant Admin</span><span aria-hidden="true">/</span><strong>@yield('section', 'Dashboard')</strong></div>
                @if ($adminMode)
                    <div class="profile-menu" aria-label="Super Admin administrative access">
                        <span class="avatar" aria-hidden="true">{{ strtoupper(substr($adminAccessAdmin->name, 0, 1)) }}</span>
                        <span class="profile-trigger-name">Super Admin</span>
                    </div>
                @else
                    <details class="profile-menu">
                    <summary class="profile-trigger">
                        <span class="avatar" aria-hidden="true">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        <span class="profile-trigger-name">{{ $user->name }}</span>
                        <span class="profile-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="profile-dropdown">
                        <span class="dropdown-label">SIGNED IN AS</span>
                        <strong>{{ $user->name }}</strong>
                        <span class="dropdown-email">{{ $user->email }}</span>
                        <a href="{{ route('restaurant.profile') }}">Account profile</a>
                        <form method="POST" action="{{ route('restaurant.logout') }}" data-confirm="Are you sure you want to sign out?">
                            @csrf
                            <button type="submit" class="dropdown-logout">Sign out</button>
                        </form>
                    </div>
                    </details>
                @endif
            </header>
            <main class="admin-content">
                @if ($adminMode)
                    <div class="notice-card" role="status" style="position:sticky;top:0;z-index:20;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding:12px 18px;margin-bottom:18px;">
                        <div>
                            <strong>Super Admin Access — Viewing: {{ $restaurant->name }}</strong>
                            <p style="margin:4px 0 0;">Temporary administrative access is active.</p>
                        </div>
                        <form method="POST" action="{{ route('super-admin.restaurants.dashboard.return') }}">
                            @csrf
                            <button class="button button-secondary" type="submit">Return to Super Admin</button>
                        </form>
                    </div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>

    <dialog class="confirm-dialog" data-confirm-dialog aria-labelledby="confirm-title" aria-describedby="confirm-message">
        <div class="confirm-icon" aria-hidden="true">↪</div>
        <h2 id="confirm-title">Sign out?</h2>
        <p id="confirm-message" data-confirm-message>Are you sure you want to sign out?</p>
        <div class="confirm-actions">
            <button class="button button-secondary" type="button" data-confirm-cancel>Stay here</button>
            <button class="button button-danger" type="button" data-confirm-accept>Sign out</button>
        </div>
    </dialog>

    @include('components.super-admin.toasts')
    <script src="{{ asset('assets/super-admin.js') }}?v={{ filemtime(public_path('assets/super-admin.js')) }}" defer></script>
</body>

</html>
