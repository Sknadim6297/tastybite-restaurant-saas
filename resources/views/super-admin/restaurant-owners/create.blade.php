@extends('layouts.super-admin')

@section('title', 'Add restaurant owner')
@section('section', 'Restaurant Owners')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">NEW ACCOUNT</span>
            <h1>Add restaurant owner</h1>
            <p>Create an owner for an existing restaurant. The owner sets their own password using a secure email link.</p>
        </div>
        <div class="toolbar-actions">
            <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.index') }}">Back to owners</a>
        </div>
    </div>

    <div class="form-card owner-form-card">
        <form method="POST" action="{{ route('super-admin.restaurant-owners.store') }}" class="form-stack" data-loading-form>
            @csrf
            <div class="form-grid">
                <div class="form-field full-span">
                    <label for="restaurant_id">Restaurant</label>
                    <select id="restaurant_id" name="restaurant_id" required @error('restaurant_id') aria-invalid="true" aria-describedby="restaurant_id_error" @enderror>
                        <option value="">Select a restaurant</option>
                        @foreach ($restaurants as $restaurant)
                            <option value="{{ $restaurant->id }}" @selected((string) old('restaurant_id') === (string) $restaurant->id)>
                                {{ $restaurant->name }} · {{ ucfirst($restaurant->status) }}
                            </option>
                        @endforeach
                    </select>
                    @error('restaurant_id')<span class="field-error" id="restaurant_id_error">{{ $message }}</span>@enderror
                    @if ($restaurants->isEmpty())
                        <span class="field-hint">Create a restaurant before adding an owner.</span>
                    @endif
                </div>
                <div class="form-field">
                    <label for="name">Owner name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" required @error('name') aria-invalid="true" aria-describedby="name_error" @enderror>
                    @error('name')<span class="field-error" id="name_error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="email">Owner email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email_error" @enderror>
                    @error('email')<span class="field-error" id="email_error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="status">Account status</label>
                    <select id="status" name="status" required @error('status') aria-invalid="true" aria-describedby="status_error" @enderror>
                        <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>Disabled</option>
                    </select>
                    @error('status')<span class="field-error" id="status_error">{{ $message }}</span>@enderror
                </div>
            </div>
            <p class="owner-form-note">A random undisclosed password is stored until the owner completes the single-use password setup link. No password is shown or emailed.</p>
            <div class="page-tools owner-form-actions">
                <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.index') }}">Cancel</a>
                <button class="button button-primary" type="submit" @disabled($restaurants->isEmpty())>
                    <span class="button-label">Create owner</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>
            </div>
        </form>
    </div>
@endsection
