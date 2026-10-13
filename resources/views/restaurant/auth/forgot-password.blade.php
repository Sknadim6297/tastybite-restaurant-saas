@extends('layouts.restaurant-admin-auth')

@section('title', 'Forgot password')

@section('content')
    <span class="eyebrow auth-eyebrow">ACCOUNT RECOVERY</span>
    <h2 class="auth-title">Reset your password</h2>
    <p class="auth-intro">Enter the email linked to your restaurant admin account.</p>

    <form method="POST" action="{{ route('restaurant.password.email') }}" class="form-stack" data-loading-form>
        @csrf
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255" placeholder="owner@restaurant.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<span class="field-error" id="email-error">{{ $message }}</span>@enderror
        </div>
        <button class="button button-primary button-full" type="submit">
            <span class="button-label">Send reset link</span><span class="button-spinner" aria-hidden="true"></span>
        </button>
    </form>

    <p class="auth-footer-link"><a class="text-link" href="{{ route('restaurant.login') }}">Back to sign in</a></p>
@endsection
