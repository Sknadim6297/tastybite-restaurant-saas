@extends('layouts.restaurant-admin')

@section('title', 'Floor & Sections')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Floor & Sections', 'description' => 'Create an unlimited number of floors and organize them into sections.'])
    @if(session('status'))<div class="notice-card" role="status">{{ session('status') }}</div>@endif
    <div class="table-management-columns">
        <section class="panel-card">
            <h2>Add a floor</h2>
            <form method="POST" action="{{ route('restaurant.tables.floors.store') }}" class="table-management-form">@csrf
                <label>Floor name<input name="name" required maxlength="100" placeholder="Ground Floor"></label>
                <label>Floor code<input name="code" required maxlength="32" placeholder="GF"></label>
                <label>Display order<input name="display_order" type="number" min="0" value="0"></label>
                <label class="table-form-wide">Description<textarea name="description" rows="2" maxlength="2000"></textarea></label>
                <button class="button button-primary" type="submit">Create floor</button>
            </form>
        </section>
        <section class="panel-card">
            <h2>Add a section</h2>
            <form method="POST" action="{{ route('restaurant.tables.sections.store') }}" class="table-management-form">@csrf
                <label>Floor<select name="floor_id" required><option value="">Choose floor</option>@foreach($floors as $floor)<option value="{{ $floor->id }}">{{ $floor->name }}</option>@endforeach</select></label>
                <label>Section name<input name="name" required maxlength="100" placeholder="Main Dining"></label>
                <label>Section code<input name="code" required maxlength="32" placeholder="MAIN"></label>
                <label>Sort order<input name="sort_order" type="number" min="0" value="0"></label>
                <label class="table-form-wide">Description<textarea name="description" rows="2" maxlength="2000"></textarea></label>
                <button class="button button-primary" type="submit">Create section</button>
            </form>
        </section>
    </div>
    <section class="table-floor-admin-list">
        @forelse($floors as $floor)
            <article class="panel-card table-floor-admin-card">
                <div class="table-section-title"><div><h2>{{ $floor->name }} <small>({{ $floor->code }})</small></h2><p>{{ $floor->tables_count }} tables · {{ $floor->available_count }} available · {{ $floor->occupied_count }} occupied · {{ $floor->capacity_total }} seats</p></div><span class="table-status-badge">{{ $floor->is_active ? 'Active' : 'Inactive' }}</span></div>
                <details><summary class="button button-secondary">Edit floor</summary>
                    <form method="POST" action="{{ route('restaurant.tables.floors.update', $floor) }}" class="table-management-form">@csrf @method('PUT')
                        <label>Floor name<input name="name" required value="{{ $floor->name }}"></label><label>Code<input name="code" required value="{{ $floor->code }}"></label>
                        <label>Display order<input name="display_order" type="number" min="0" value="{{ $floor->display_order }}"></label>
                        <label class="table-form-wide">Description<textarea name="description">{{ $floor->description }}</textarea></label>
                        <label class="table-checkbox"><input type="checkbox" name="is_active" value="1" @checked($floor->is_active)> Active</label>
                        <button class="button button-primary">Save floor</button>
                    </form>
                </details>
                <div class="table-section-tiles">
                    @forelse($floor->sections as $section)
                        <details class="table-section-tile">
                            <summary><strong>{{ $section->name }}</strong><span>{{ $section->tables_count }} tables</span></summary>
                            <form method="POST" action="{{ route('restaurant.tables.sections.update', $section) }}" class="table-management-form">@csrf @method('PUT')
                                <input type="hidden" name="floor_id" value="{{ $floor->id }}">
                                <label>Name<input name="name" required value="{{ $section->name }}"></label><label>Code<input name="code" required value="{{ $section->code }}"></label>
                                <label>Sort order<input name="sort_order" type="number" value="{{ $section->sort_order }}"></label><label class="table-form-wide">Description<textarea name="description">{{ $section->description }}</textarea></label>
                                <label class="table-checkbox"><input type="checkbox" name="is_active" value="1" @checked($section->is_active)> Active</label>
                                <button class="button button-primary">Save section</button>
                            </form>
                            <form method="POST" action="{{ route('restaurant.tables.sections.archive', $section) }}" onsubmit="return confirm('Archive this section?');">@csrf @method('DELETE')<button class="button button-danger" @disabled($section->tables->whereIn('status', ['occupied', 'cleaning'])->isNotEmpty())>Archive section</button></form>
                        </details>
                    @empty<p class="muted-copy">No sections on this floor.</p>@endforelse
                </div>
                <form method="POST" action="{{ route('restaurant.tables.floors.archive', $floor) }}" onsubmit="return confirm('Archive this floor? Historical records will be retained.');">@csrf @method('DELETE')<button class="button button-danger" @disabled($floor->occupied_count > 0)>Archive floor</button></form>
            </article>
        @empty<div class="panel-card empty-state"><h2>No floors yet</h2><p>Create a floor to begin organizing tables.</p></div>@endforelse
    </section>
@endsection
