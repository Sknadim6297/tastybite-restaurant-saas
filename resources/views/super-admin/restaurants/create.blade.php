@extends('layouts.super-admin')

@section('title', 'Add restaurant')
@section('section', 'Restaurants')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">NEW RESTAURANT</span>
            <h1>Add restaurant</h1>
            <p>Create a restaurant and owner account atomically. The owner sets a password through a one-time email link.</p>
        </div>
        <a class="button button-secondary" href="{{ route('super-admin.restaurants.index') }}">Back to list</a>
    </div>

    <div class="form-card">
        <form method="POST" action="{{ route('super-admin.restaurants.store') }}" class="form-stack" data-loading-form>
            @csrf

            <div class="form-grid">
                <div class="full-span">
                    <h2 style="margin:0 0 18px; color:var(--navy); font-size:18px;">Restaurant details</h2>
                </div>

                <div class="form-field">
                    <label for="restaurant_name">Restaurant name</label>
                    <input id="restaurant_name" name="restaurant[name]" type="text" value="{{ old('restaurant.name') }}" required maxlength="255" @error('restaurant.name') aria-invalid="true" aria-describedby="restaurant_name_error" @enderror>
                    @error('restaurant.name')<span class="field-error" id="restaurant_name_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="restaurant_slug">Restaurant slug</label>
                    <input id="restaurant_slug" name="restaurant[slug]" type="text" value="{{ old('restaurant.slug') }}" required maxlength="255" placeholder="my-restaurant" @error('restaurant.slug') aria-invalid="true" aria-describedby="restaurant_slug_error" @enderror>
                    @error('restaurant.slug')<span class="field-error" id="restaurant_slug_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="restaurant_email">Restaurant email</label>
                    <input id="restaurant_email" name="restaurant[email]" type="email" value="{{ old('restaurant.email') }}" required maxlength="255" @error('restaurant.email') aria-invalid="true" aria-describedby="restaurant_email_error" @enderror>
                    @error('restaurant.email')<span class="field-error" id="restaurant_email_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="restaurant_phone">Phone</label>
                    <input id="restaurant_phone" name="restaurant[phone]" type="tel" value="{{ old('restaurant.phone') }}" maxlength="50" @error('restaurant.phone') aria-invalid="true" aria-describedby="restaurant_phone_error" @enderror>
                    @error('restaurant.phone')<span class="field-error" id="restaurant_phone_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field full-span">
                    <label for="restaurant_address">Address</label>
                    <textarea id="restaurant_address" name="restaurant[address]" rows="3" maxlength="500" @error('restaurant.address') aria-invalid="true" aria-describedby="restaurant_address_error" @enderror>{{ old('restaurant.address') }}</textarea>
                    @error('restaurant.address')<span class="field-error" id="restaurant_address_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="restaurant_status">Status</label>
                    <select id="restaurant_status" name="restaurant[status]" required>
                        <option value="active" @selected(old('restaurant.status', 'active') === 'active')>Active</option>
                        <option value="suspended" @selected(old('restaurant.status') === 'suspended')>Suspended</option>
                        <option value="inactive" @selected(old('restaurant.status') === 'inactive')>Inactive</option>
                    </select>
                    @error('restaurant.status')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="restaurant_approval_status">Approval status</label>
                    <select id="restaurant_approval_status" name="restaurant[approval_status]" required>
                        <option value="approved" @selected(old('restaurant.approval_status', 'approved') === 'approved')>Approved</option>
                        <option value="pending" @selected(old('restaurant.approval_status') === 'pending')>Pending review</option>
                    </select>
                    <span class="field-hint">Pending restaurants can sign in to view their status but cannot access operational features.</span>
                    @error('restaurant.approval_status')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="full-span">
                    <h2 style="margin:24px 0 18px; color:var(--navy); font-size:18px;">Owner account</h2>
                </div>

                <div class="form-field">
                    <label for="owner_name">Owner name</label>
                    <input id="owner_name" name="owner[name]" type="text" value="{{ old('owner.name') }}" required maxlength="255" @error('owner.name') aria-invalid="true" aria-describedby="owner_name_error" @enderror>
                    @error('owner.name')<span class="field-error" id="owner_name_error">{{ $message }}</span>@enderror
                </div>

                <div class="form-field">
                    <label for="owner_email">Owner email</label>
                    <input id="owner_email" name="owner[email]" type="email" value="{{ old('owner.email') }}" required maxlength="255" @error('owner.email') aria-invalid="true" aria-describedby="owner_email_error" @enderror>
                    @error('owner.email')<span class="field-error" id="owner_email_error">{{ $message }}</span>@enderror
                </div>

                <div class="field-hint full-span">
                    A random, undisclosed password is generated for the new account. The owner must use the expiring setup link to choose their password.
                </div>
            </div>

            <div class="page-tools" style="margin-top:26px; margin-bottom:0; justify-content:flex-end;">
                <a class="button button-secondary" href="{{ route('super-admin.restaurants.index') }}">Cancel</a>
                <button class="button button-primary" type="submit">
                    <span class="button-label">Create restaurant</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>
            </div>
        </form>
    </div>
@endsection
