@extends('layouts.restaurant-admin')

@section('title', $product->exists ? 'Edit Product' : 'Add Product')
@section('section', 'Menu Management')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">MENU MANAGEMENT</span><h1>{{ $product->exists ? 'Edit product' : 'Add product' }}</h1><p>Product changes apply only to {{ $restaurant->name }}.</p></div>
    </div>

    <form method="POST" action="{{ $product->exists ? route('restaurant.menu.products.update', $product) : route('restaurant.menu.products.store') }}" enctype="multipart/form-data" class="panel-card form-stack">
        @csrf
        @if($product->exists) @method('PUT') @endif

        <div class="form-field">
            <label for="product-name">Name</label>
            <input id="product-name" name="name" value="{{ old('name', $product->name) }}" maxlength="255" required autocomplete="off">
            @error('name')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="product-category">Category</label>
            <select id="product-category" name="category_id">
                <option value="">Uncategorized</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (Inactive)' }}</option>
                @endforeach
            </select>
            @error('category_id')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="product-description">Description <span class="field-hint-inline">(optional)</span></label>
            <textarea id="product-description" name="description" rows="4" maxlength="10000">{{ old('description', $product->description) }}</textarea>
            @error('description')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="product-price">Price (INR)</label>
            <input id="product-price" name="price" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('price', $product->price) }}" required>
            @error('price')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="product-image">Product image (JPG, PNG, or WebP; up to 5 MB)</label>
            <input id="product-image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
            @error('image')<span class="field-error">{{ $message }}</span>@enderror
            <div class="product-image-preview" data-product-image-preview-container @if(!$product->image_path) hidden @endif>
                <img
                    data-product-image-preview
                    src="{{ $product->image_url }}"
                    alt="{{ $product->name }}"
                    @if(!$product->image_path) hidden @endif
                >
                @if($product->image_path)
                    <label><input type="checkbox" name="remove_image" value="1" @checked((bool) old('remove_image'))> <span>Remove current image</span></label>
                @endif
            </div>
        </div>

        <div class="form-field">
            <label for="product-food-type">Food type</label>
            <select id="product-food-type" name="food_type" required>
                <option value="veg" @selected(old('food_type', $product->food_type ?? 'veg') === 'veg')>Veg</option>
                <option value="non_veg" @selected(old('food_type', $product->food_type) === 'non_veg')>Non-Veg</option>
            </select>
            @error('food_type')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="product-sort-order">Sort order</label>
            <input id="product-sort-order" name="sort_order" type="number" min="0" max="4294967295" step="1" value="{{ old('sort_order', $product->sort_order ?? 0) }}" required>
            <span class="field-hint">Lower numbers appear first.</span>
            @error('sort_order')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-option-grid">
            <div class="form-field checkbox-field">
                <input type="hidden" name="is_available" value="0">
                <label><input type="checkbox" name="is_available" value="1" @checked((bool) old('is_available', $product->exists ? $product->is_available : true))> <span>Available for sale</span></label>
                @error('is_available')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-field checkbox-field">
                <input type="hidden" name="is_active" value="0">
                <label><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $product->exists ? $product->is_active : true))> <span>Active</span></label>
                @error('is_active')<span class="field-error">{{ $message }}</span>@enderror
            </div>
        </div>

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('restaurant.menu.products.index') }}">Cancel</a>
            <button class="button button-primary" type="submit">{{ $product->exists ? 'Save changes' : 'Create product' }}</button>
        </div>
    </form>
    <script src="{{ asset('assets/menu-product.js') }}" defer></script>
@endsection
