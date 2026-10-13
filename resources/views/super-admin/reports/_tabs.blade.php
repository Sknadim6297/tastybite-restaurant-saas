<div class="report-tabs" aria-label="Report sections">
    <a @class(['is-active' => $active === 'overview']) href="{{ route('super-admin.reports.index') }}">Overview</a>
    <a @class(['is-active' => $active === 'restaurants']) href="{{ route('super-admin.reports.restaurants') }}">Restaurants</a>
    <a @class(['is-active' => $active === 'subscriptions']) href="{{ route('super-admin.reports.subscriptions') }}">Subscriptions</a>
    <a @class(['is-active' => $active === 'payments']) href="{{ route('super-admin.reports.payments') }}">Payments</a>
    <a @class(['is-active' => $active === 'revenue']) href="{{ route('super-admin.reports.revenue') }}">Revenue</a>
</div>
