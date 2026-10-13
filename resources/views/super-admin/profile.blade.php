@extends('layouts.super-admin')

@section('title', 'Profile')
@section('section', 'Profile')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">ACCOUNT</span>
        <h1>Your profile</h1>
        <p>Account details for your Super Admin access.</p>
    </div>
</div>
<section class="profile-card" aria-labelledby="profile-title">
    <div class="profile-card-heading">
        <span class="avatar avatar-large" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($superAdmin->name, 0, 1)) }}</span>
        <div>
            <h2 id="profile-title">{{ $superAdmin->name }}</h2>
            <p>Super Admin</p>
        </div>
        <span class="account-status"><span aria-hidden="true"></span> Active</span>
    </div>
    <dl class="profile-details">
        <div>
            <dt>Full name</dt>
            <dd>{{ $superAdmin->name }}</dd>
        </div>
        <div>
            <dt>Email address</dt>
            <dd>{{ $superAdmin->email }}</dd>
        </div>
        <div>
            <dt>Account created</dt>
            <dd>{{ $superAdmin->created_at->format('j M Y') }}</dd>
        </div>
        <div>
            <dt>Account type</dt>
            <dd>Platform Super Admin</dd>
        </div>
    </dl>
    <p class="profile-note">Profile changes are managed by an authorized server-side administrator.</p>
</section>
@endsection