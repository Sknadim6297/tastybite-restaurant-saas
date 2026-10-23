@extends('layouts.restaurant-admin')

@section('title', 'Menu Products')
@section('section', 'Menu Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">MENU MANAGEMENT</span><h1>Products</h1><p>Manage the products available to your restaurant POS.</p></div>
        <a class="button button-primary" href="{{ route('restaurant.menu.products.create') }}">Add product</a>
    </div>

    <form method="GET" action="{{ route('restaurant.menu.products.index') }}" class="panel-card product-filter-toolbar" id="product-filters">
        <div class="form-field">
            <label for="product-search">Search products</label>
            <input id="product-search" type="search" name="search" value="{{ $search }}" maxlength="255" placeholder="Search name or description">
        </div>
        <div class="form-field">
            <label for="product-category">Category</label>
            <select id="product-category" name="category_id">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="button button-secondary product-filter-clear" type="button" id="clear-product-filters">Clear filters</button>
        <span class="product-filter-status" id="product-filter-status" role="status" aria-live="polite" hidden>Loading products...</span>
    </form>

    <div id="product-results" aria-live="polite">
        @include('restaurant.menu-products.partials.results')
    </div>

    <script src="{{ asset('assets/menu-product-filters.js') }}?v={{ filemtime(public_path('assets/menu-product-filters.js')) }}" defer></script>
@endsection
