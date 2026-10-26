<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · TastyBite Restaurant</title>
    @include('components.app-fonts')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('assets/super-admin.css') }}?v={{ filemtime(public_path('assets/super-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/restaurant-panel.css') }}?v={{ filemtime(public_path('assets/restaurant-panel.css')) }}">
</head>

<body class="admin-page restaurant-admin-page">
    @php
        $adminAccessAdmin = request()->attributes->get('restaurant_admin_access_admin');
        $adminAccessRestaurant = request()->attributes->get('restaurant_admin_access_restaurant');
        $adminAccessToken = request()->attributes->get('restaurant_admin_access_token');
        $adminMode = $adminAccessRestaurant !== null && $adminAccessAdmin !== null;
        $user = app(\App\Services\RestaurantAccessContext::class)->owner();
        $breadcrumbSection = trim($__env->yieldContent('section', 'Dashboard'));
        $breadcrumbTitle = trim($__env->yieldContent('title', 'Dashboard'));
        $showBreadcrumbSection = strcasecmp($breadcrumbSection, $breadcrumbTitle) !== 0;
        $breadcrumbSectionUrl = request()->routeIs('restaurant.tables.*') ? route('restaurant.tables.overview') : null;
        $isDashboard = request()->routeIs('restaurant.dashboard');
    @endphp
    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar">
            <a class="brand-lockup brand-lockup-light sidebar-brand" href="{{ route('restaurant.dashboard') }}">
                <span class="brand-name">Tasty<span>Bite</span></span>
            </a>
            <nav class="sidebar-nav" aria-label="Restaurant admin navigation">
                <span class="sidebar-caption">OPERATIONS</span>
                <a href="{{ route('restaurant.dashboard') }}" class="sidebar-link @if(request()->routeIs('restaurant.dashboard')) is-active @endif">
                    <span class="nav-icon" aria-hidden="true"><i class="bi bi-grid-1x2"></i></span><span>Dashboard</span>
                </a>
                <a href="{{ route('pos.index') }}" class="sidebar-link @if(request()->routeIs('pos.index')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span><span>POS Billing</span></a>
                <details class="sidebar-menu-group" @if(request()->routeIs('restaurant.orders.*')) open @endif>
                    <summary @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.orders.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-bag-check"></i></span><span>Orders Management</span><span class="sidebar-menu-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></summary>
                    <div class="sidebar-menu-children">
                        <a href="{{ route('restaurant.orders.overview') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.overview')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-grid-1x2"></i></span><span>Order Overview</span></a>
                        <a href="{{ route('restaurant.orders.all') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.all', 'restaurant.orders.show', 'restaurant.orders.status', 'restaurant.orders.cancel')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span><span>All Orders</span></a>
                        <a href="{{ route('restaurant.orders.dine-in') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.dine-in')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-cup-hot"></i></span><span>Dine-In Orders</span></a>
                        <a href="{{ route('restaurant.orders.fulfillment', ['type' => 'takeaway']) }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.fulfillment')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-bicycle"></i></span><span>Takeaway &amp; Delivery</span></a>
                        <a href="{{ route('restaurant.orders.billing') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.billing')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-credit-card"></i></span><span>Billing &amp; Payments</span></a>
                        <a href="{{ route('restaurant.orders.reports') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.orders.reports*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span><span>Order Reports</span></a>
                    </div>
                </details>
                <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-fire"></i></span><span>Kitchen / KOT</span><span class="nav-soon">Soon</span></span>
                <details class="sidebar-menu-group" @if(request()->routeIs('restaurant.tables.*')) open @endif>
                    <summary @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.tables.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-table"></i></span><span>Table Management</span><span class="sidebar-menu-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></summary>
                    <div class="sidebar-menu-children">
                        <a href="{{ route('restaurant.tables.overview') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.tables.overview', 'restaurant.tables.operations')])><span class="nav-icon"><i class="bi bi-grid"></i></span><span>Table Dashboard</span></a>
                        <a href="{{ route('restaurant.tables.manage', ['tab' => 'tables']) }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.tables.manage', 'restaurant.tables.index', 'restaurant.tables.floors', 'restaurant.tables.show')])><span class="nav-icon"><i class="bi bi-list-ul"></i></span><span>Manage Tables</span></a>
                        <a href="{{ route('restaurant.tables.reservations') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.tables.reservations*')])><span class="nav-icon"><i class="bi bi-calendar-check"></i></span><span>Reservations</span></a>
                        <a href="{{ route('restaurant.tables.reports') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.tables.reports*')])><span class="nav-icon"><i class="bi bi-bar-chart"></i></span><span>Table Reports</span></a>
                    </div>
                </details>
                <details class="sidebar-menu-group" @if(request()->routeIs('restaurant.menu.categories.*', 'restaurant.menu.products.*')) open @endif>
                    <summary @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.menu.categories.*', 'restaurant.menu.products.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span><span>Menu Management</span><span class="sidebar-menu-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></summary>
                    <div class="sidebar-menu-children">
                        <a href="{{ route('restaurant.menu.categories.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.menu.categories.*')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></span><span>Categories</span></a>
                        <a href="{{ route('restaurant.menu.products.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.menu.products.*')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-egg-fried"></i></span><span>Products</span></a>
                    </div>
                </details>
                    <details class="sidebar-menu-group" @if(request()->routeIs('restaurant.inventory.*')) open @endif>
                        <summary @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.inventory.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span><span>Inventory Management</span><span class="sidebar-menu-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></summary>
                        <div class="sidebar-menu-children">
                            <a href="{{ route('restaurant.inventory.overview') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.overview')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-grid-1x2"></i></span><span>Overview</span></a>
                            <a href="{{ route('restaurant.inventory.items.index') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.items.*', 'restaurant.inventory.categories.*', 'restaurant.inventory.units.*', 'restaurant.inventory.locations.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span><span>Inventory Items</span></a>
                            <a href="{{ route('restaurant.inventory.stock.create', ['kind' => 'opening']) }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.stock.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></span><span>Stock Management</span></a>
                            <a href="{{ route('restaurant.inventory.counts.index') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.counts.*', 'restaurant.inventory.wastage.*', 'restaurant.inventory.batches')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span><span>Stock Control</span></a>
                            <a href="{{ route('restaurant.inventory.recipes.index') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.recipes.*', 'restaurant.inventory.vendor-mappings.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-journal-bookmark"></i></span><span>Recipes &amp; Mapping</span></a>
                            <a href="{{ route('restaurant.inventory.reports') }}" @class(['sidebar-link', 'sidebar-sublink', 'is-active' => request()->routeIs('restaurant.inventory.ledger', 'restaurant.inventory.reports', 'restaurant.inventory.settings*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span><span>Reports &amp; Settings</span></a>
                        </div>
                    </details>
                    <details class="sidebar-menu-group" @if(request()->routeIs('restaurant.vendors.*')) open @endif>
                        <summary @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.vendors.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-truck"></i></span><span>Vendor Management</span><span class="sidebar-menu-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span></summary>
                        <div class="sidebar-menu-children">
                            <a href="{{ route('restaurant.vendors.overview') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.vendors.overview')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span><span>Vendor Overview</span></a>
                            <a href="{{ route('restaurant.vendors.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.vendors.index', 'restaurant.vendors.show', 'restaurant.vendors.edit')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-people"></i></span><span>All Vendors</span></a>
                            <a href="{{ route('restaurant.vendors.create') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.vendors.create')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-person-plus"></i></span><span>Add Vendor</span></a>
                            <a href="{{ route('restaurant.vendors.purchase-orders.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.vendors.purchase-orders.*')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-clipboard"></i></span><span>Purchase Orders</span></a>
                            <a href="{{ route('restaurant.vendors.goods-received.index') }}" class="sidebar-link sidebar-sublink @if(request()->routeIs('restaurant.vendors.goods-received.*')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span><span>Goods Received</span></a>
                        </div>
                    </details>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-people"></i></span><span>Staff Management</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-person-lines-fill"></i></span><span>Customers</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-wallet2"></i></span><span>Expenses</span><span class="nav-soon">Soon</span></span>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-bar-chart"></i></span><span>Reports</span><span class="nav-soon">Soon</span></span>
                    <a href="{{ route('restaurant.subscription') }}" class="sidebar-link @if(request()->routeIs('restaurant.subscription')) is-active @endif"><span class="nav-icon" aria-hidden="true"><i class="bi bi-credit-card"></i></span><span>Subscription</span></a>
                    <a href="{{ route('restaurant.support.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.support.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-life-preserver"></i></span><span>Support Tickets</span></a>
                    <a href="{{ route('restaurant.announcements.index') }}" @class(['sidebar-link', 'is-active' => request()->routeIs('restaurant.announcements.*')])><span class="nav-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span><span>Announcements</span></a>
                    <span class="sidebar-link is-disabled" aria-disabled="true"><span class="nav-icon" aria-hidden="true"><i class="bi bi-gear"></i></span><span>Settings</span><span class="nav-soon">Soon</span></span>
                @unless ($adminMode)
                    <form method="POST" action="{{ route('restaurant.logout') }}" data-confirm="Are you sure you want to sign out?">
                        @csrf
                        <button type="submit" class="sidebar-link sidebar-logout-button"><span class="nav-icon" aria-hidden="true"><i class="bi bi-box-arrow-left"></i></span><span>Logout</span></button>
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
                    <span aria-hidden="true"><i class="bi bi-list"></i></span>
                </button>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    @if($isDashboard)
                        <strong aria-current="page">{{ $breadcrumbTitle }}</strong>
                    @else
                        <a href="{{ route('restaurant.dashboard') }}">Dashboard</a><span aria-hidden="true">/</span>
                        @if($showBreadcrumbSection)
                            @if($breadcrumbSectionUrl)
                                <a href="{{ $breadcrumbSectionUrl }}">{{ $breadcrumbSection }}</a>
                            @else
                                <span>{{ $breadcrumbSection }}</span>
                            @endif
                            <span aria-hidden="true">/</span>
                        @endif
                        <strong aria-current="page">{{ $breadcrumbTitle }}</strong>
                    @endif
                </nav>
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
                        <span class="profile-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
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
                @if(request()->routeIs('restaurant.inventory.*'))
                    @include('restaurant.inventory.partials.workspace-tabs')
                @endif
                @yield('content')
            </main>
        </div>
    </div>

    <dialog class="confirm-dialog" data-confirm-dialog aria-labelledby="confirm-title" aria-describedby="confirm-message">
        <div class="confirm-icon" aria-hidden="true"><i class="bi bi-box-arrow-right"></i></div>
        <h2 id="confirm-title">Sign out?</h2>
        <p id="confirm-message" data-confirm-message>Are you sure you want to sign out?</p>
        <div class="confirm-actions">
            <button class="button button-secondary" type="button" data-confirm-cancel>Stay here</button>
            <button class="button button-danger" type="button" data-confirm-accept>Sign out</button>
        </div>
    </dialog>

    @include('components.super-admin.toasts')
    <script src="{{ asset('assets/super-admin.js') }}?v={{ filemtime(public_path('assets/super-admin.js')) }}" defer></script>
    @if ($adminMode)
        <script
            src="{{ asset('assets/restaurant-admin-access.js') }}?v={{ filemtime(public_path('assets/restaurant-admin-access.js')) }}"
            data-access-token="{{ $adminAccessToken }}"
            defer
        ></script>
    @endif
    @if(request()->routeIs('restaurant.inventory.*'))
        <script src="{{ asset('assets/restaurant-workspace-tabs.js') }}?v={{ filemtime(public_path('assets/restaurant-workspace-tabs.js')) }}" defer></script>
    @endif
</body>

</html>
