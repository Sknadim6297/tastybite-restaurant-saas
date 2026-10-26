<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Super Admin') · TastyBite</title>
    @include('components.app-fonts')
    <link rel="stylesheet" href="{{ asset('assets/super-admin.css') }}">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand-panel" aria-label="TastyBite platform">
            <a class="brand-lockup brand-lockup-light" href="{{ route('super-admin.login') }}" aria-label="TastyBite Super Admin">
                <span class="brand-name">Tasty<span>Bite</span></span>
            </a>
            <div class="auth-brand-copy">
                <span class="eyebrow">PLATFORM ADMINISTRATION</span>
                <h1>One clear view<br>of every table.</h1>
                <p>The control centre for the TastyBite restaurant platform.</p>
            </div>
            <span class="brand-panel-foot">TASTYBITE · SUPER ADMIN</span>
        </section>

        <section class="auth-main">
            <div class="auth-mobile-brand">
                <a class="brand-lockup" href="{{ route('super-admin.login') }}">
                    <span class="brand-name">Tasty<span>Bite</span></span>
                </a>
            </div>
            <div class="auth-card">
                @yield('content')
            </div>
            <p class="auth-footer">© {{ now()->year }} TastyBite. Platform administration.</p>
        </section>
    </main>

    @include('components.super-admin.toasts')
    <script src="{{ asset('assets/super-admin.js') }}" defer></script>
</body>
</html>
