@extends('layouts.super-admin-auth')

@section('title', 'Forgot password')

@section('content')
<a class="back-link" href="{{ route('super-admin.login') }}"><span aria-hidden="true">←</span> Back to sign in</a>
<span class="eyebrow auth-eyebrow">ACCOUNT RECOVERY</span>
<h2 class="auth-title">Forgot your password?</h2>
<p class="auth-intro">Enter the email address associated with your Super Admin account. If it matches an active account, we’ll send reset instructions.</p>

<form method="POST" action="{{ route('super-admin.password.email') }}" class="form-stack" data-loading-form>
    @csrf
    <div class="form-field">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255" placeholder="you@company.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<span class="field-error" id="email-error">{{ $message }}</span>@enderror
    </div>
    <button class="button button-primary button-full" type="submit">
        <span class="button-label">Send reset instructions</span><span class="button-spinner" aria-hidden="true"></span>
    </button>
</form>
<p class="auth-security"><span aria-hidden="true">▣</span> Reset links expire after 60 minutes</p>
@endsection