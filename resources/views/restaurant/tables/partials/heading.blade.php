<div class="page-heading table-page-heading">
    <div>
        <span class="eyebrow">TABLE MANAGEMENT</span>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </div>
    @isset($action)
        <div>{{ $action }}</div>
    @endisset
</div>
