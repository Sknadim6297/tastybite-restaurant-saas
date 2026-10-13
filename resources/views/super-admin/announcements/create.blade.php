@extends('layouts.super-admin')

@section('title', 'Create Announcement')
@section('section', 'Announcements')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">NEW ANNOUNCEMENT</span>
            <h1>Create announcement</h1>
            <p>Announcements are delivered only inside the restaurant owner dashboard.</p>
        </div>
        <a class="button button-secondary" href="{{ route('super-admin.announcements.index') }}">Back to list</a>
    </div>

    <div class="form-card">
        <form method="POST" action="{{ route('super-admin.announcements.store') }}" class="form-stack" data-loading-form>
            @csrf
            <div class="form-grid">
                <div class="form-field full-span">
                    <label for="title">Title</label>
                    <input id="title" name="title" value="{{ old('title') }}" required minlength="4" maxlength="180">
                    @error('title')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field full-span">
                    <label for="body">Announcement text</label>
                    <textarea id="body" name="body" rows="8" required minlength="10" maxlength="10000">{{ old('body') }}</textarea>
                    @error('body')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="audience">Audience</label>
                    <select id="audience" name="audience" required>
                        <option value="all" @selected(old('audience', 'all') === 'all')>All active restaurants with active owners</option>
                        <option value="selected" @selected(old('audience') === 'selected')>Selected restaurants</option>
                    </select>
                    @error('audience')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label for="expires_at">Optional expiry</label>
                    <input id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}">
                    @error('expires_at')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field full-span">
                    <label for="restaurant_ids">Selected restaurants</label>
                    <select id="restaurant_ids" name="restaurant_ids[]" multiple size="8" aria-describedby="audience-help">
                        @foreach ($restaurants as $restaurant)
                            <option value="{{ $restaurant->id }}" @selected(in_array($restaurant->id, (array) old('restaurant_ids', [])))>{{ $restaurant->name }} ({{ $restaurant->slug }})</option>
                        @endforeach
                    </select>
                    <span class="field-hint" id="audience-help">Choose one or more only for a selected audience. This list contains active restaurants with active owner accounts.</span>
                    @error('restaurant_ids')<span class="field-error">{{ $message }}</span>@enderror
                    @error('restaurant_ids.*')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="notice-card" role="note"><strong>Delivery note</strong><p>Publishing makes this notice visible in the owners' Announcements page. No email or external notification is sent.</p></div>
            <div class="page-tools" style="margin:0; justify-content:flex-end;">
                <a class="button button-secondary" href="{{ route('super-admin.announcements.index') }}">Cancel</a>
                <button class="button button-primary" type="submit">Save draft</button>
            </div>
        </form>
    </div>
@endsection
