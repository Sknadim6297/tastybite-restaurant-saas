@extends('layouts.super-admin-auth')

@section('title', 'Set a new password')

@section('content')
    <a class="back-link" href="{{ route('super-admin.login') }}"><span aria-hidden="true">←</span> Back to sign in</a>
    <span class="eyebrow auth-eyebrow">ACCOUNT RECOVERY</span>
    <h2 class="auth-title">Set a new password</h2>
    <p class="auth-intro">Choose a strong password you haven’t used for this account before.</p>

    <form method="POST" action="{{ route('super-admin.password.update') }}" class="form-stack" data-loading-form>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required maxlength="255">
            @error('email')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="password">New password</label>
            <div class="password-input">
                <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" aria-describedby="password-help password-strength">
                <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            <span class="field-hint" id="password-help">At least 12 characters with uppercase, lowercase, a number and a symbol.</span>
            <span class="field-hint password-strength" id="password-strength" data-password-strength aria-live="polite"></span>
            @error('password')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="password_confirmation">Confirm new password</label>
            <div class="password-input">
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12">
                <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </div>
        </div>
        <button class="button button-primary button-full" type="submit">
            <span class="button-label">Save new password</span><span class="button-spinner" aria-hidden="true"></span>
        </button>
    </form>
@endsection
