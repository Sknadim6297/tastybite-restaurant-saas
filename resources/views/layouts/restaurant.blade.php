<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>@yield('title', 'Tasty Bite')</title>

    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
    />
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="{{ asset('assets/style.css') }}?v={{ hash_file('sha256', public_path('assets/style.css')) }}" />
  </head>
  <body class="pos-page">
    @if (request()->attributes->has('restaurant_admin_access_restaurant'))
      @php($adminAccessToken = request()->attributes->get('restaurant_admin_access_token'))
      <div role="status" style="position:sticky;top:0;z-index:1100;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:10px 18px;background:#fff4e8;border-bottom:1px solid #f2c28d;color:#763d0b;">
        <strong>Super Admin Access — Viewing: {{ request()->attributes->get('restaurant_admin_access_restaurant')->name }}</strong>
        <form method="POST" action="{{ route('super-admin.restaurants.dashboard.return') }}">
          @csrf
          <button class="btn btn-outline-dark btn-sm" type="submit">Return to Super Admin</button>
        </form>
      </div>
    @endif
    @yield('content')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/script.js') }}?v={{ hash_file('sha256', public_path('assets/script.js')) }}"></script>
    @if (request()->attributes->has('restaurant_admin_access_restaurant'))
      <script
        src="{{ asset('assets/restaurant-admin-access.js') }}?v={{ filemtime(public_path('assets/restaurant-admin-access.js')) }}"
        data-access-token="{{ $adminAccessToken }}"
        defer
      ></script>
    @endif
  </body>
</html>
