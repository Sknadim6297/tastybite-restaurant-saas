<form method="GET" action="{{ route('restaurant.tables.manage') }}" class="panel-card table-filter-toolbar" data-table-filter>
    <input type="hidden" name="tab" value="sections">
    <label class="table-filter-field table-filter-search">
        <span class="table-filter-label">Search sections</span>
        <span class="table-search-wrap">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="section_search" value="{{ request('section_search') }}" placeholder="Section name or code" data-debounce>
        </span>
    </label>
    <span class="table-loading" role="status" hidden>Updating…</span>
    <a class="button button-secondary" href="{{ route('restaurant.tables.manage', ['tab' => 'sections']) }}">Clear</a>
</form>

<section class="panel-card table-compact-card-shell">
    <div class="table-compact-card-header">
        <div>
            <h2>Add a section</h2>
            <p>Create a dining section within a floor.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('restaurant.tables.sections.store') }}" class="table-management-form table-compact-form">
        @csrf

        <label class="table-form-field">
            <span>Floor</span>
            <select name="floor_id" required>
                <option value="">Choose floor</option>
                @foreach($floorOptions as $floor)
                    <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="table-form-field">
            <span>Section name</span>
            <input name="name" required maxlength="100" placeholder="Main Dining">
        </label>
        <label class="table-form-field">
            <span>Section code</span>
            <input name="code" required maxlength="32" placeholder="MAIN">
        </label>
        <label class="table-form-field">
            <span>Sort order</span>
            <input name="sort_order" type="number" min="0" value="0">
        </label>
        <div class="table-form-field table-form-toggle-field">
            <label class="table-toggle" for="section-active-toggle">
                <input id="section-active-toggle" type="checkbox" name="is_active" value="1" checked>
                <span class="table-toggle-indicator" aria-hidden="true"></span>
                <span>Active</span>
            </label>
        </div>
        <label class="table-form-field table-form-field-full">
            <span>Description</span>
            <textarea name="description" rows="2" maxlength="2000" placeholder="Optional section description"></textarea>
        </label>

        <div class="table-form-actions">
            <button class="button button-primary" type="submit">Create section</button>
        </div>
    </form>
</section>

<section class="table-floor-admin-list">
    @forelse($sections as $section)
        <article class="panel-card table-compact-card">
            <div class="table-compact-card-top">
                <div class="table-compact-card-title">
                    <h3>{{ $section->name }}</h3>
                    <span class="table-compact-card-subtitle">Code: {{ $section->code }}</span>
                </div>
                <div class="table-compact-card-actions">
                    <span class="table-status-badge {{ $section->is_active ? 'is-active' : 'is-inactive' }}">{{ $section->is_active ? 'Active' : 'Inactive' }}</span>

                    <details class="table-card-inline-editor">
                        <summary class="button button-secondary">Edit</summary>
                        <div class="table-inline-editor-panel">
                            <form method="POST" action="{{ route('restaurant.tables.sections.update', $section) }}" class="table-management-form table-inline-form">
                                @csrf
                                @method('PUT')
                                <label class="table-form-field">
                                    <span>Floor</span>
                                    <select name="floor_id" required>
                                        @foreach($floorOptions as $floor)
                                            <option value="{{ $floor->id }}" @selected($section->floor_id === $floor->id)>{{ $floor->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="table-form-field">
                                    <span>Name</span>
                                    <input name="name" required value="{{ $section->name }}">
                                </label>
                                <label class="table-form-field">
                                    <span>Code</span>
                                    <input name="code" required value="{{ $section->code }}">
                                </label>
                                <label class="table-form-field">
                                    <span>Sort order</span>
                                    <input name="sort_order" type="number" value="{{ $section->sort_order }}">
                                </label>
                                <div class="table-form-field table-form-toggle-field">
                                    <label class="table-toggle" for="section-toggle-{{ $section->id }}">
                                        <input id="section-toggle-{{ $section->id }}" type="checkbox" name="is_active" value="1" @checked($section->is_active)>
                                        <span class="table-toggle-indicator" aria-hidden="true"></span>
                                        <span>Active</span>
                                    </label>
                                </div>
                                <label class="table-form-field table-form-field-full">
                                    <span>Description</span>
                                    <textarea name="description" rows="2">{{ $section->description }}</textarea>
                                </label>
                                <div class="table-form-actions table-inline-form-actions">
                                    <button type="button" class="button button-secondary" data-collapse-details="section-{{ $section->id }}">Cancel</button>
                                    <button class="button button-primary" type="submit">Save changes</button>
                                </div>
                            </form>
                        </div>
                    </details>

                    <form method="POST" action="{{ route('restaurant.tables.sections.archive', $section) }}" onsubmit="return confirm('Archive this section?');">
                        @csrf
                        @method('DELETE')
                        <button class="button button-danger" type="submit" @disabled($section->occupied_count > 0)>Archive</button>
                    </form>
                </div>
            </div>

            <div class="table-compact-metrics" aria-label="Section statistics">
                <span class="table-compact-metric"><strong>{{ $section->floor?->name ?? 'Unassigned' }}</strong><small>Floor</small></span>
                <span class="table-compact-metric"><strong>{{ $section->tables_count }}</strong><small>Tables</small></span>
                <span class="table-compact-metric"><strong>{{ $section->capacity_total }}</strong><small>Seats</small></span>
                <span class="table-compact-metric"><strong>{{ $section->available_count }}</strong><small>Available</small></span>
            </div>
        </article>
    @empty
        <div class="panel-card empty-state">
            <h2>No sections found</h2>
            <p>Create your first dining section to organize tables.</p>
        </div>
    @endforelse
</section>

<script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
<script>
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-collapse-details]');
        if (!trigger) return;
        const details = trigger.closest('.table-card-inline-editor');
        if (details) details.removeAttribute('open');
    });
</script>