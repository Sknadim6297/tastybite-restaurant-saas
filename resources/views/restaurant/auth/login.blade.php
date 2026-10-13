@extends('layouts.restaurant-admin-auth')

@section('title', 'Sign in')

@section('content')
    <span class="eyebrow auth-eyebrow">RESTAURANT ADMIN PORTAL</span>
    <h2 class="auth-title">Welcome back</h2>
    <p class="auth-intro">Access your restaurant dashboard securely.</p>

    <form method="POST" action="{{ route('restaurant.login.submit') }}" class="form-stack" data-loading-form>
        @csrf
        <div class="form-field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255" placeholder="owner@restaurant.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<span class="field-error" id="email-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <div class="label-row"><label for="password">Password</label><a class="text-link" href="{{ route('restaurant.password.request') }}">Forgot password?</a></div>
            <div class="password-input">
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            @error('password')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <button class="button button-primary button-full" type="submit">
            <span class="button-label">Sign in to dashboard</span><span class="button-spinner" aria-hidden="true"></span>
        </button>
    </form>
    <p class="auth-security"><span aria-hidden="true">▣</span> Secure restaurant access</p>
@endsection
