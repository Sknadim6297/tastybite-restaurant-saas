@extends('layouts.super-admin')

@section('title', $announcement->title)
@section('section', 'Announcements')

@section('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">ANNOUNCEMENT DETAIL</span>
            <h1>{{ $announcement->title }}</h1>
            <p>Created by {{ $announcement->author?->name ?? 'Unavailable administrator' }} on {{ $announcement->created_at->format('M j, Y H:i') }}.</p>
        </div>
        <a class="button button-secondary" href="{{ route('super-admin.announcements.index') }}">Back to list</a>
    </div>

    <div class="summary-row">
        <div class="summary-tile"><strong>{{ ucfirst($announcement->status) }}</strong><span>Status</span></div>
        <div class="summary-tile"><strong>{{ $recipientCount }}</strong><span>Eligible restaurant recipients</span></div>
        <div class="summary-tile"><strong>{{ $readCount }}</strong><span>Restaurants marked read</span></div>
        <div class="summary-tile"><strong>{{ $announcement->expires_at?->format('M j, Y H:i') ?? 'Never' }}</strong><span>Expiry</span></div>
    </div>

    @if ($announcement->status === 'draft')
        <div class="form-card">
            <form method="POST" action="{{ route('super-admin.announcements.update', $announcement) }}" class="form-stack" data-loading-form>
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="form-field full-span"><label for="title">Title</label><input id="title" name="title" value="{{ old('title', $announcement->title) }}" required minlength="4" maxlength="180">@error('title')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field full-span"><label for="body">Announcement text</label><textarea id="body" name="body" rows="7" required minlength="10" maxlength="10000">{{ old('body', $announcement->body) }}</textarea>@error('body')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field"><label for="audience">Audience</label><select id="audience" name="audience" required><option value="all" @selected(old('audience', $announcement->audience) === 'all')>All active restaurants with active owners</option><option value="selected" @selected(old('audience', $announcement->audience) === 'selected')>Selected restaurants</option></select>@error('audience')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field"><label for="expires_at">Optional expiry</label><input id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at', $announcement->expires_at?->format('Y-m-d\TH:i')) }}">@error('expires_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field full-span"><label for="restaurant_ids">Selected restaurants</label><select id="restaurant_ids" name="restaurant_ids[]" multiple size="8">
                        @foreach ($restaurants as $restaurant)
                            <option value="{{ $restaurant->id }}" @selected(in_array($restaurant->id, (array) old('restaurant_ids', $announcement->restaurants->modelKeys())))>{{ $restaurant->name }} ({{ $restaurant->slug }})</option>
                        @endforeach
                    </select><span class="field-hint">Choose one or more only when the selected audience is chosen.</span>@error('restaurant_ids')<span class="field-error">{{ $message }}</span>@enderror</div>
                </div>
                <div class="page-tools" style="margin:0; justify-content:flex-end;"><button class="button button-secondary" type="submit">Save draft</button></div>
            </form>
        </div>
    @else
        <div class="form-card">
            <div class="detail-grid">
                <div class="detail-item"><span>Audience</span><strong>{{ $announcement->audience === 'all' ? 'All active restaurants with active owners' : 'Selected restaurants' }}</strong></div>
                <div class="detail-item"><span>Published</span><strong>{{ $announcement->published_at?->format('M j, Y H:i') ?? '—' }}</strong></div>
                @if ($announcement->audience === 'selected')
                    <div class="detail-item full-span"><span>Selected restaurants</span><strong>{{ $announcement->restaurants->pluck('name')->join(', ') ?: 'No selected restaurants' }}</strong></div>
                @endif
                <div class="detail-item full-span"><span>Message</span><strong style="white-space:pre-wrap;">{{ $announcement->body }}</strong></div>
            </div>
        </div>
    @endif

    <div class="page-tools" style="margin-top:20px; justify-content:flex-end;">
        @if ($announcement->status === 'draft')
            <form method="POST" action="{{ route('super-admin.announcements.publish', $announcement) }}" data-loading-form data-confirm="Publish this announcement to {{ $recipientCount }} eligible restaurant(s)? No email will be sent." data-confirm-title="Publish announcement?" data-confirm-action="Publish">
                @csrf
                <button class="button button-primary" type="submit">Publish now</button>
            </form>
        @elseif ($announcement->status === 'published')
            <form method="POST" action="{{ route('super-admin.announcements.archive', $announcement) }}" data-loading-form data-confirm="Archive this announcement? It will no longer be visible to restaurant owners." data-confirm-title="Archive announcement?" data-confirm-action="Archive">
                @csrf
                <button class="button button-danger" type="submit">Archive</button>
            </form>
        @endif
    </div>
@endsection
