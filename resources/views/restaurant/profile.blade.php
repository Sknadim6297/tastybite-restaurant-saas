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

    <section class="two-column-card-grid">
        <div class="panel-card">
            <h2>Personal information</h2>
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
            <h2>Change password</h2>
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
