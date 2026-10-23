<form method="GET" action="{{ route('restaurant.tables.manage') }}" class="panel-card table-filter-toolbar" data-table-filter>
    <input type="hidden" name="tab" value="floors">
    <label class="table-filter-field table-filter-search">
        <span class="table-filter-label">Search floors</span>
        <span class="table-search-wrap">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="floor_search" value="{{ request('floor_search') }}" placeholder="Floor name or code" data-debounce>
        </span>
    </label>
    <span class="table-loading" role="status" hidden>Updating…</span>
    <a class="button button-secondary" href="{{ route('restaurant.tables.manage', ['tab' => 'floors']) }}">Clear</a>
</form>

<section class="panel-card table-compact-card-shell">
    <div class="table-compact-card-header">
        <div>
            <h2>Add a floor</h2>
            <p>Create a dining floor for your restaurant.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('restaurant.tables.floors.store') }}" class="table-management-form table-compact-form">
        @csrf

        <label class="table-form-field">
            <span>Floor name</span>
            <input name="name" required maxlength="100" placeholder="Ground Floor">
        </label>
        <label class="table-form-field">
            <span>Floor code</span>
            <input name="code" required maxlength="32" placeholder="GF">
        </label>
        <label class="table-form-field">
            <span>Display order</span>
            <input name="display_order" type="number" min="0" value="0">
        </label>
        <div class="table-form-field table-form-toggle-field">
            <label class="table-toggle" for="floor-active-toggle">
                <input id="floor-active-toggle" type="checkbox" name="is_active" value="1" checked>
                <span class="table-toggle-indicator" aria-hidden="true"></span>
                <span>Active</span>
            </label>
        </div>
        <label class="table-form-field table-form-field-full">
            <span>Description</span>
            <textarea name="description" rows="2" maxlength="2000" placeholder="Optional floor description"></textarea>
        </label>

        <div class="table-form-actions">
            <button class="button button-primary" type="submit">Create floor</button>
        </div>
    </form>
</section>

<section class="table-floor-admin-list">
    @forelse($floors as $floor)
        <article class="panel-card table-compact-card">
            <div class="table-compact-card-top">
                <div class="table-compact-card-title">
                    <h3>{{ $floor->name }}</h3>
                    <span class="table-compact-card-subtitle">Code: {{ $floor->code }}</span>
                </div>
                <div class="table-compact-card-actions">
                    <span class="table-status-badge {{ $floor->is_active ? 'is-active' : 'is-inactive' }}">{{ $floor->is_active ? 'Active' : 'Inactive' }}</span>

                    <details class="table-card-inline-editor">
                        <summary class="button button-secondary">Edit</summary>
                        <div class="table-inline-editor-panel">
                            <form method="POST" action="{{ route('restaurant.tables.floors.update', $floor) }}" class="table-management-form table-inline-form">
                                @csrf
                                @method('PUT')
                                <label class="table-form-field">
                                    <span>Floor name</span>
                                    <input name="name" required value="{{ $floor->name }}">
                                </label>
                                <label class="table-form-field">
                                    <span>Code</span>
                                    <input name="code" required value="{{ $floor->code }}">
                                </label>
                                <label class="table-form-field">
                                    <span>Display order</span>
                                    <input name="display_order" type="number" min="0" value="{{ $floor->display_order }}">
                                </label>
                                <div class="table-form-field table-form-toggle-field">
                                    <label class="table-toggle" for="floor-toggle-{{ $floor->id }}">
                                        <input id="floor-toggle-{{ $floor->id }}" type="checkbox" name="is_active" value="1" @checked($floor->is_active)>
                                        <span class="table-toggle-indicator" aria-hidden="true"></span>
                                        <span>Active</span>
                                    </label>
                                </div>
                                <label class="table-form-field table-form-field-full">
                                    <span>Description</span>
                                    <textarea name="description" rows="2">{{ $floor->description }}</textarea>
                                </label>
                                <div class="table-form-actions table-inline-form-actions">
                                    <button type="button" class="button button-secondary" data-collapse-details="floor-{{ $floor->id }}">Cancel</button>
                                    <button class="button button-primary" type="submit">Save changes</button>
                                </div>
                            </form>
                        </div>
                    </details>

                    <form method="POST" action="{{ route('restaurant.tables.floors.archive', $floor) }}" onsubmit="return confirm('Archive this floor? Historical records will be retained.');">
                        @csrf
                        @method('DELETE')
                        <button class="button button-danger" type="submit" @disabled($floor->occupied_count > 0)>Archive</button>
                    </form>
                </div>
            </div>

            <div class="table-compact-metrics" aria-label="Floor statistics">
                <span class="table-compact-metric"><strong>{{ $floor->tables_count }}</strong><small>Total tables</small></span>
                <span class="table-compact-metric"><strong>{{ $floor->available_count }}</strong><small>Available</small></span>
                <span class="table-compact-metric"><strong>{{ $floor->occupied_count }}</strong><small>Occupied</small></span>
                <span class="table-compact-metric"><strong>{{ $floor->capacity_total }}</strong><small>Seats</small></span>
            </div>
        </article>
    @empty
        <div class="panel-card empty-state">
            <h2>No floors found</h2>
            <p>Create a floor or change the search.</p>
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