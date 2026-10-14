@extends('layouts.restaurant-admin')

@section('title', $category->exists ? 'Edit Category' : 'Add Category')
@section('section', 'Menu Management')

@section('content')
    @php($selectedIcon = old('icon', $category->icon))
    @php($categoryImageUrl = $category->image_url)
    <div class="page-heading">
        <div><span class="eyebrow">MENU MANAGEMENT</span><h1>{{ $category->exists ? 'Edit category' : 'Add category' }}</h1><p>Category changes apply only to {{ $restaurant->name }}.</p></div>
    </div>

    <form method="POST" action="{{ $category->exists ? route('restaurant.menu.categories.update', $category) : route('restaurant.menu.categories.store') }}" enctype="multipart/form-data" class="panel-card form-stack">
        @csrf
        @if($category->exists) @method('PUT') @endif

        <div class="form-field">
            <label for="category-name">Name</label>
            <input id="category-name" name="name" value="{{ old('name', $category->name) }}" maxlength="255" required autocomplete="off">
            @error('name')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="category-description">Description <span class="field-hint-inline">(optional)</span></label>
            <textarea id="category-description" name="description" rows="4" maxlength="10000">{{ old('description', $category->description) }}</textarea>
            @error('description')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="category-icon-search">Category icon <span class="field-hint-inline">(optional)</span></label>
            <input id="category-icon-search" type="search" placeholder="Search food icons" autocomplete="off" data-category-icon-search>
            <input type="hidden" name="icon" value="{{ $selectedIcon }}" data-category-icon-value>
            <div class="category-icon-picker" role="group" aria-label="Choose a category icon">
                <button
                    class="category-icon-option"
                    type="button"
                    data-category-icon-choice=""
                    data-category-icon-label="default no icon"
                    aria-pressed="{{ $selectedIcon ? 'false' : 'true' }}"
                >
                    <i class="bi bi-grid" aria-hidden="true"></i>
                    <span>Default</span>
                </button>
                @foreach(\App\Models\RestaurantMenuCategory::ICONS as $icon => $label)
                    <button
                        class="category-icon-option"
                        type="button"
                        data-category-icon-choice="{{ $icon }}"
                        data-category-icon-label="{{ strtolower($label.' '.$icon) }}"
                        aria-pressed="{{ $selectedIcon === $icon ? 'true' : 'false' }}"
                    >
                        <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
                        <span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
            <div class="category-icon-preview" data-category-icon-preview>
                <span>Selected icon</span>
                <i class="bi bi-{{ $selectedIcon ?: 'grid' }}" aria-hidden="true"></i>
            </div>
            @error('icon')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="category-image">Category image <span class="field-hint-inline">(optional; JPG, PNG, or WebP, up to 5 MB)</span></label>
            <input id="category-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" data-category-image-input>
            @error('image')<span class="field-error">{{ $message }}</span>@enderror
            <div class="category-image-preview" data-category-image-preview-container @if(!$category->image_path) hidden @endif>
                <img
                    src="{{ $categoryImageUrl }}"
                    alt="{{ $category->name }}"
                    data-category-image-preview
                    @if(!$category->image_path) hidden @endif
                >
                @if($category->image_path)
                    <label><input type="checkbox" name="remove_image" value="1" @checked((bool) old('remove_image'))> Remove current image</label>
                @endif
            </div>
            @error('remove_image')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field">
            <label for="category-sort-order">Sort order</label>
            <input id="category-sort-order" name="sort_order" type="number" min="0" max="4294967295" step="1" value="{{ old('sort_order', $category->sort_order ?? 0) }}" required>
            <span class="field-hint">Lower numbers appear first.</span>
            @error('sort_order')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-field checkbox-field">
            <input type="hidden" name="is_active" value="0">
            <label><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $category->exists ? $category->is_active : true))> Active</label>
            @error('is_active')<span class="field-error">{{ $message }}</span>@enderror
        </div>

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('restaurant.menu.categories.index') }}">Cancel</a>
            <button class="button button-primary" type="submit">{{ $category->exists ? 'Save changes' : 'Create category' }}</button>
        </div>
    </form>
    <script src="{{ asset('assets/menu-category.js') }}" defer></script>
@endsection
