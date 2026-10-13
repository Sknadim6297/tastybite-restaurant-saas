@extends('layouts.super-admin')

@section('title', 'Edit owner')
@section('section', 'Restaurant Owners')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">OWNER PROFILE</span>
            <h1>Edit {{ $owner->name }}</h1>
            <p>Update permitted profile details. Role and restaurant association cannot be changed here.</p>
        </div>
        <div class="toolbar-actions">
            <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.show', $owner) }}">Back to owner</a>
        </div>
    </div>

    <div class="form-card owner-form-card">
        <form method="POST" action="{{ route('super-admin.restaurant-owners.update', $owner) }}" class="form-stack" data-loading-form>
            @csrf
            @method('PUT')
            <div class="owner-readonly-banner">
                <span class="eyebrow">ASSIGNED RESTAURANT</span>
                <strong>{{ $owner->restaurant?->name ?? 'Unassigned' }}</strong>
            </div>
            <div class="form-grid">
                <div class="form-field">
                    <label for="name">Owner name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $owner->name) }}" maxlength="255" required @error('name') aria-invalid="true" aria-describedby="name_error" @enderror>
                    @error('name')<span class="field-error" id="name_error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="email">Owner email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $owner->email) }}" maxlength="255" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email_error" @enderror>
                    @error('email')<span class="field-error" id="email_error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="page-tools owner-form-actions">
                <a class="button button-secondary" href="{{ route('super-admin.restaurant-owners.show', $owner) }}">Cancel</a>
                <button class="button button-primary" type="submit">
                    <span class="button-label">Save changes</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>
            </div>
        </form>
    </div>
@endsection
