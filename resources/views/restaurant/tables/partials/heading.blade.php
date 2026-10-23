<div class="page-heading table-page-heading">
    <div>
        <span class="eyebrow">TABLE MANAGEMENT</span>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
        <nav class="table-breadcrumbs" aria-label="Breadcrumb">
            <a href="{{ route('restaurant.dashboard') }}">Dashboard</a><span aria-hidden="true">/</span>
            <a href="{{ route('restaurant.tables.overview') }}">Table Management</a><span aria-hidden="true">/</span>
            <span aria-current="page">{{ $title }}</span>
        </nav>
    </div>
    @isset($action)
        <div>{{ $action }}</div>
    @endisset
</div>
