@extends('layouts.super-admin')

@section('title', 'Edit restaurant')
@section('section', 'Restaurants')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">UPDATE RESTAURANT</span>
            <h1>Edit {{ $restaurant->name }}</h1>
            <p>Update restaurant details and tenant status.</p>
        </div>
        <div class="toolbar-actions">
            <a class="button button-secondary" href="{{ route('super-admin.restaurants.show', $restaurant) }}">Back to details</a>
        </div>
    </div>

    <div class="form-card">
        <form method="POST" action="{{ route('super-admin.restaurants.update', $restaurant) }}" class="form-stack" data-loading-form>
            @csrf
            @method('PUT')

            <div class="form-grid">
                <div class="form-field">
                    <label for="name">Restaurant name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $restaurant->name) }}" required maxlength="255" @error('name') aria-invalid="true" aria-describedby="name_error" @enderror>
                    @error('name')<span class="field-error" id="name_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="slug">Restaurant slug</label>
                    <input id="slug" name="slug" type="text" value="{{ old('slug', $restaurant->slug) }}" required maxlength="255" @error('slug') aria-invalid="true" aria-describedby="slug_error" @enderror>
                    @error('slug')<span class="field-error" id="slug_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="email">Restaurant email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $restaurant->email) }}" required maxlength="255" @error('email') aria-invalid="true" aria-describedby="email_error" @enderror>
                    @error('email')<span class="field-error" id="email_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="phone">Phone</label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone', $restaurant->phone) }}" maxlength="50" @error('phone') aria-invalid="true" aria-describedby="phone_error" @enderror>
                    @error('phone')<span class="field-error" id="phone_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field full-span">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="4" maxlength="500" @error('address') aria-invalid="true" aria-describedby="address_error" @enderror>{{ old('address', $restaurant->address) }}</textarea>
                    @error('address')<span class="field-error" id="address_error">{{ $message }}</span>@enderror
                </div>

            </div>

            <div class="page-tools" style="margin-top:26px; margin-bottom:0; justify-content:flex-end;">
                <a class="button button-secondary" href="{{ route('super-admin.restaurants.show', $restaurant) }}">Cancel</a>
                <button class="button button-primary" type="submit">
                    <span class="button-label">Save changes</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>
            </div>
        </form>
    </div>
@endsection
