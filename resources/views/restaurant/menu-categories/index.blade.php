@extends('layouts.restaurant-admin')

@section('title', 'Menu Categories')
@section('section', 'Menu Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">MENU MANAGEMENT</span><h1>Categories</h1><p>Create and organize the categories shown in your restaurant POS.</p></div>
        <a class="button button-primary" href="{{ route('restaurant.menu.categories.create') }}">Add category</a>
    </div>

    <div class="table-shell">
        <table class="data-table">
            <thead>
                <tr><th>Category</th><th>Icon</th><th>Status</th><th>Sort order</th><th>Products</th><th>Updated</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td>
                            <strong>{{ $category->name }}</strong>
                            @if($category->description)<br><span class="meta-label">{{ $category->description }}</span>@endif
                        </td>
                        <td>
                            @if($category->image_path)
                                <img class="menu-category-thumbnail" src="{{ $category->image_url }}" alt="" loading="lazy">
                            @endif
                            <span class="menu-category-icon" aria-label="{{ \App\Models\RestaurantMenuCategory::ICONS[$category->icon] ?? 'Default category' }}">
                                <i class="bi bi-{{ \App\Models\RestaurantMenuCategory::iconClassFor($category->icon) }}" aria-hidden="true"></i>
                            </span>
                        </td>
                        <td><span class="status-pill status-{{ $category->is_active ? 'active' : 'inactive' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $category->sort_order }}</td>
                        <td>{{ $category->products_count }}</td>
                        <td>{{ $category->updated_at->timezone(config('app.timezone'))->format('M j, Y') }}</td>
                        <td>
                            <div class="action-stack">
                                <a class="button button-secondary" href="{{ route('restaurant.menu.categories.edit', $category) }}">Edit</a>
                                @unless (request()->attributes->has('restaurant_support_request') || request()->attributes->has('restaurant_admin_access_restaurant'))
                                    <form method="POST" action="{{ route('restaurant.menu.categories.status', $category) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="button button-secondary" type="submit">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('restaurant.menu.categories.destroy', $category) }}" data-confirm="Delete this category? Categories with assigned products cannot be deleted.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-danger" type="submit">Delete</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><h3>No categories yet</h3><p>Add your first category to organize menu items in POS.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($categories->hasPages())
        <div class="page-tools pagination-tools">{{ $categories->links() }}</div>
    @endif
@endsection
