<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Restaurant Admin') · TastyBite</title>
    <link rel="stylesheet" href="{{ asset('assets/super-admin.css') }}">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand-panel" aria-label="TastyBite restaurant operations">
            <a class="brand-lockup brand-lockup-light" href="{{ route('restaurant.login') }}" aria-label="TastyBite Restaurant Admin">
                <span class="brand-name">Tasty<span>Bite</span></span>
            </a>
            <div class="auth-brand-copy">
                <span class="eyebrow">RESTAURANT OPERATIONS</span>
                <h1>Run every service<br>from one place.</h1>
                <p>Manage staff, orders, service flow, and your restaurant from a secure admin workspace.</p>
            </div>
            <span class="brand-panel-foot">TASTYBITE · RESTAURANT ADMIN</span>
        </section>

        <section class="auth-main">
            <div class="auth-mobile-brand">
                <a class="brand-lockup" href="{{ route('restaurant.login') }}">
                    <span class="brand-name">Tasty<span>Bite</span></span>
                </a>
            </div>
            <div class="auth-card">
                @yield('content')
            </div>
            <p class="auth-footer">© {{ now()->year }} TastyBite. Restaurant administration.</p>
        </section>
    </main>

    @include('components.super-admin.toasts')
    <script src="{{ asset('assets/super-admin.js') }}" defer></script>
</body>
</html>
