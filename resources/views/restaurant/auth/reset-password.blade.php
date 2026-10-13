@extends('layouts.restaurant-admin-auth')

@section('title', 'Reset password')

@section('content')
    <span class="eyebrow auth-eyebrow">SECURE PASSWORD RESET</span>
    <h2 class="auth-title">Create a new password</h2>
    <p class="auth-intro">Choose a strong password for your restaurant account.</p>

    <form method="POST" action="{{ route('restaurant.password.update') }}" class="form-stack" data-loading-form>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required maxlength="255" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<span class="field-error" id="email-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="password">New password</label>
            <div class="password-input">
                <input id="password" name="password" type="password" autocomplete="new-password" required maxlength="255" aria-describedby="password-strength" @error('password') aria-invalid="true" @enderror>
                <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            <div class="password-hint" data-password-strength></div>
            @error('password')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="password_confirmation">Confirm new password</label>
            <div class="password-input">
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required maxlength="255">
                <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </div>
        </div>
        <button class="button button-primary button-full" type="submit">
            <span class="button-label">Reset password</span><span class="button-spinner" aria-hidden="true"></span>
        </button>
    </form>

    <p class="auth-footer-link"><a class="text-link" href="{{ route('restaurant.login') }}">Back to sign in</a></p>
@endsection
