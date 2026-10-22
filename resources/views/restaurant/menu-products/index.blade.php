@extends('layouts.restaurant-admin')

@section('title', 'Menu Products')
@section('section', 'Menu Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">MENU MANAGEMENT</span><h1>Products</h1><p>Manage the products available to your restaurant POS.</p></div>
        <a class="button button-primary" href="{{ route('restaurant.menu.products.create') }}">Add product</a>
    </div>

    <form method="GET" action="{{ route('restaurant.menu.products.index') }}" class="panel-card report-filters" data-auto-filter>
        <div class="form-grid">
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
            <div class="form-actions">
                <button class="button button-primary" type="submit">Apply filters</button>
            </div>
        </div>
        @if($search !== '' || $categoryId !== null)
            <a class="button button-secondary" href="{{ route('restaurant.menu.products.index') }}" data-auto-filter-reset>Clear</a>
        @endif
    </form>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr><th>Product</th><th>Category</th><th>Price</th><th>Type</th><th>Availability</th><th>Status</th><th>Sort</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    <tr>
                        <td>
                            <img class="menu-product-thumbnail" src="{{ $product->image_url }}" alt="" loading="lazy">
                            <strong>{{ $product->name }}</strong>
                            @if($product->description)<br><span class="meta-label">{{ $product->description }}</span>@endif
                        </td>
                        <td>{{ $product->category?->name ?? 'Uncategorized' }}</td>
                        <td>₹{{ number_format((float) $product->price, 2) }}</td>
                        <td>{{ $product->food_type === 'non_veg' ? 'Non-Veg' : 'Veg' }}</td>
                        <td><span class="status-pill status-{{ $product->is_available ? 'active' : 'inactive' }}">{{ $product->is_available ? 'Available' : 'Unavailable' }}</span></td>
                        <td><span class="status-pill status-{{ $product->is_active ? 'active' : 'inactive' }}">{{ $product->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $product->sort_order }}</td>
                        <td>
                            <div class="action-stack">
                                <a class="button button-secondary" href="{{ route('restaurant.menu.products.edit', $product) }}">Edit</a>
                                @unless (request()->attributes->has('restaurant_admin_access_restaurant'))
                                    <form method="POST" action="{{ route('restaurant.menu.products.destroy', $product) }}" data-confirm="Delete this product and its stored image?">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-danger" type="submit">Delete</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><h3>{{ $search !== '' ? 'No matching products' : 'No products yet' }}</h3><p>{{ $search !== '' ? 'Try a different search term.' : 'Add your first product to make it available for POS.' }}</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($products->hasPages())
        <div class="page-tools pagination-tools">{{ $products->links() }}</div>
    @endif
@endsection
