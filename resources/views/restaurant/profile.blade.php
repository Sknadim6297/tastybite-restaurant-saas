@extends('layouts.restaurant-admin')

@section('title', 'Profile')
@section('section', 'Profile')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">ACCOUNT</span>
            <h1>Profile details</h1>
            <p>Manage your restaurant admin account and security settings.</p>
        </div>
    </div>

    <section class="two-column-card-grid profile-sections">
        <div class="panel-card profile-branding-card">
            <h2 class="profile-section-heading">Restaurant branding</h2>
            <p class="profile-section-description">Upload a logo or restaurant photo for the POS header. Use JPG, PNG, or WebP images between 64×64 and 2048×2048 pixels, up to 5 MB.</p>
            <form method="POST" action="{{ route('restaurant.profile.branding') }}" enctype="multipart/form-data" class="form-stack">
                @csrf
                @method('PUT')
                <div class="form-field branding-upload-field">
                    <label for="restaurant-logo">Logo image</label>
                    <input id="restaurant-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" data-branding-image-input>
                    @error('logo')<span class="field-error">{{ $message }}</span>@enderror
                    <div class="branding-image-preview" data-branding-image-container @if(!$restaurant->logo_url) hidden @endif>
                        <img
                            src="{{ $restaurant->logo_url ?? '' }}"
                            alt="Restaurant logo preview"
                            data-branding-image-preview
                            @if(!$restaurant->logo_url) hidden @endif
                        >
                        <div class="branding-preview-details">
                            <span class="meta-label">Logo preview</span>
                            @if($restaurant->logo_path)
                                <label><input type="checkbox" name="remove_logo" value="1" @checked((bool) old('remove_logo'))> <span>Remove current logo</span></label>
                            @endif
                        </div>
                    </div>
                    @error('remove_logo')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <button class="button button-primary" type="submit">Save branding</button>
            </form>
            <script src="{{ asset('assets/restaurant-branding.js') }}" defer></script>
        </div>

        <div class="panel-card">
            <h2 class="profile-section-heading">Personal information</h2>
            <p class="profile-section-description">Update the name associated with your restaurant administrator account.</p>
            <form method="POST" action="{{ route('restaurant.profile.update') }}" class="form-stack" data-loading-form>
                @csrf
                @method('PUT')
                <div class="form-field">
                    <label for="name">Full name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="255" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<span class="field-error" id="name-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field form-field-readonly">
                    <label>Email address</label>
                    <input type="text" value="{{ $user->email }}" readonly>
                </div>
                <button class="button button-primary" type="submit">
                    <span class="button-label">Save profile</span><span class="button-spinner" aria-hidden="true"></span>
                </button>
            </form>
        </div>

        <div class="panel-card">
            <h2 class="profile-section-heading">Security settings</h2>
            <p class="profile-section-description">Change your password to help keep this account secure.</p>
            <form method="POST" action="{{ route('restaurant.profile.password') }}" class="form-stack" data-loading-form>
                @csrf
                @method('PUT')
                <div class="form-field">
                    <label for="current_password">Current password</label>
                    <div class="password-input">
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    @error('current_password', 'passwordUpdate')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="password">New password</label>
                    <div class="password-input">
                        <input id="password" name="password" type="password" autocomplete="new-password" required maxlength="255" aria-describedby="password-strength" @error('password', 'passwordUpdate') aria-invalid="true" @enderror>
                        <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <div class="password-hint" data-password-strength></div>
                    @error('password', 'passwordUpdate')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="password_confirmation">Confirm password</label>
                    <div class="password-input">
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required maxlength="255">
                        <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>
                <button class="button button-primary" type="submit">
                    <span class="button-label">Update password</span><span class="button-spinner" aria-hidden="true"></span>
                </button>
            </form>
        </div>
    </section>
@endsection
