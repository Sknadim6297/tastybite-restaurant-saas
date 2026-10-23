<details class="panel-card table-form-panel" open>
    <summary><strong>Add a table</strong><span>Create one table or a numbered batch</span></summary>
    <div class="table-form-panel-inner">
        <form method="POST" action="{{ route('restaurant.tables.store') }}" class="table-management-form" data-floor-dependent-section>
            @csrf
            <div class="table-form-field">
                <label for="add-table-floor">Floor</label>
                <select id="add-table-floor" name="floor_id" data-floor-select required>
                    <option value="">Choose floor</option>
                    @foreach($floorOptions as $floor)
                        <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="table-form-field">
                <label for="add-table-section">Section</label>
                <select id="add-table-section" name="section_id" data-section-select>
                    <option value="">No section</option>
                    @foreach($sections as $section)
                        <option value="{{ $section->id }}" data-floor-id="{{ $section->floor_id }}">{{ $section->floor?->name }} · {{ $section->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="table-form-field">
                <label for="add-table-number">Table number</label>
                <input id="add-table-number" name="table_number" maxlength="32" required placeholder="G01">
            </div>
            <div class="table-form-field">
                <label for="add-table-name">Table name</label>
                <input id="add-table-name" name="name" maxlength="100" placeholder="Window table">
            </div>
            <div class="table-form-field">
                <label for="add-table-capacity">Capacity</label>
                <input id="add-table-capacity" type="number" name="capacity" min="1" max="1000" required value="4">
            </div>
            <div class="table-form-field">
                <label for="add-table-shape">Shape</label>
                <select id="add-table-shape" name="shape" required>
                    <option value="round">Round</option>
                    <option value="square">Square</option>
                    <option value="rectangle" selected>Rectangle</option>
                </select>
            </div>
            <div class="table-form-field">
                <label for="add-table-type">Type</label>
                <select id="add-table-type" name="type" required>
                    @foreach(['standard' => 'Standard', 'family' => 'Family', 'vip' => 'VIP', 'outdoor' => 'Outdoor', 'banquet' => 'Banquet'] as $value => $label)
                        <option value="{{ $value }}" @selected($value === 'standard')>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="table-form-field">
                <label for="add-table-sort-order">Sort order</label>
                <input id="add-table-sort-order" type="number" name="sort_order" min="0" value="0">
            </div>
            <div class="table-form-field table-form-field-full">
                <label for="add-table-description">Description</label>
                <textarea id="add-table-description" name="description" rows="2" maxlength="2000" placeholder="Optional notes for this table."></textarea>
            </div>
            <div class="table-form-field table-form-toggle-field">
                <label class="table-toggle" for="add-table-active">
                    <input id="add-table-active" type="checkbox" name="is_active" value="1" checked>
                    <span class="table-toggle-indicator" aria-hidden="true"></span>
                    <span>Active</span>
                </label>
            </div>
            <div class="table-form-actions">
                <button class="button button-primary" type="submit">Create table</button>
            </div>
        </form>

        <div class="table-section-divider" aria-hidden="true"></div>

        <div class="table-bulk-form-wrap">
            <h3>Bulk create tables</h3>
            <form method="POST" action="{{ route('restaurant.tables.bulk') }}" class="table-bulk-form" data-floor-dependent-section>
                @csrf
                <div class="table-form-field">
                    <label for="bulk-table-floor">Floor</label>
                    <select id="bulk-table-floor" name="floor_id" data-floor-select required>
                        <option value="">Choose floor</option>
                        @foreach($floorOptions as $floor)
                            <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="table-form-field">
                    <label for="bulk-table-section">Section</label>
                    <select id="bulk-table-section" name="section_id" data-section-select>
                        <option value="">No section</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" data-floor-id="{{ $section->floor_id }}">{{ $section->floor?->name }} · {{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="table-form-field">
                    <label for="bulk-table-prefix">Prefix</label>
                    <input id="bulk-table-prefix" name="prefix" required maxlength="20" value="G">
                </div>
                <div class="table-form-field">
                    <label for="bulk-table-start">Starting number</label>
                    <input id="bulk-table-start" type="number" name="start" min="0" value="1" required>
                </div>
                <div class="table-form-field">
                    <label for="bulk-table-count">Number of tables</label>
                    <input id="bulk-table-count" type="number" name="count" min="1" max="100" value="10" required>
                </div>
                <div class="table-form-field">
                    <label for="bulk-table-capacity">Capacity</label>
                    <input id="bulk-table-capacity" type="number" name="capacity" min="1" max="1000" value="4" required>
                </div>
                <div class="table-form-actions">
                    <button class="button button-secondary" type="submit">Create batch</button>
                </div>
            </form>
        </div>
    </div>
</details>

<form method="GET" action="{{ route('restaurant.tables.manage') }}" class="panel-card table-filter-toolbar" data-table-filter>
    <input type="hidden" name="tab" value="tables">
    <label class="table-filter-field table-filter-search">
        <span class="table-filter-label">Search</span>
        <span class="table-search-wrap">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Table number or name" data-debounce>
        </span>
    </label>
    <label class="table-filter-field">Floor<select name="floor" data-auto-submit><option value="">All floors</option>@foreach($floorOptions as $floor)<option value="{{ $floor->id }}" @selected(($filters['floor'] ?? '') == $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
    <label class="table-filter-field">Section<select name="section" data-auto-submit><option value="">All sections</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected(($filters['section'] ?? '') == $section->id)>{{ $section->name }}</option>@endforeach</select></label>
    <label class="table-filter-field">Status<select name="status" data-auto-submit><option value="">All statuses</option>@foreach(['available', 'occupied', 'cleaning', 'out_of_service'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
    <label class="table-filter-field">Capacity<input type="number" name="capacity" min="1" value="{{ $filters['capacity'] ?? '' }}" data-auto-submit></label>
    <label class="table-filter-field">Active<select name="active" data-auto-submit><option value="">All</option><option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option><option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option></select></label>
    <span class="table-loading" role="status" hidden>Updating…</span>
    <a class="button button-tertiary" href="{{ route('restaurant.tables.manage', ['tab' => 'tables']) }}">Clear filters</a>
</form>

<div class="table-list-shell"><table class="data-table table-management-list">
    <thead><tr><th>Table number / name</th><th>Floor / Section</th><th>Capacity</th><th>Status</th><th>Active order</th><th>Actions</th></tr></thead>
    <tbody>@forelse($tables as $table)
        <tr>
            <td><a href="{{ route('restaurant.tables.show', $table) }}"><strong>{{ $table->table_number }}</strong></a><small>{{ $table->name }}</small></td>
            <td>{{ $table->floor?->name }}<small>{{ $table->section?->name ?? '—' }}</small></td>
            <td>{{ $table->capacity }}</td>
            <td><span class="table-status-badge">{{ str($table->status)->replace('_', ' ')->title() }}</span><small>{{ $table->is_active ? 'Active' : 'Inactive' }}</small></td>
            <td>@if($table->activeOccupancy?->order)<a href="{{ route('restaurant.orders.show', $table->activeOccupancy->order) }}">{{ $table->activeOccupancy->order->order_number }}</a>@else—@endif</td>
            <td><div class="table-row-actions">
                <details><summary class="button button-secondary">Edit</summary>
                    <form method="POST" action="{{ route('restaurant.tables.update', $table) }}" class="table-inline-edit">@csrf @method('PUT')
                        <label>Number<input name="table_number" value="{{ $table->table_number }}" required></label><label>Name<input name="name" value="{{ $table->name }}"></label>
                        <label>Floor<select name="floor_id" required>@foreach($floorOptions as $floor)<option value="{{ $floor->id }}" @selected($table->floor_id === $floor->id)>{{ $floor->name }}</option>@endforeach</select></label>
                        <label>Section<select name="section_id"><option value="">No section</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected($table->section_id === $section->id)>{{ $section->floor?->name }} · {{ $section->name }}</option>@endforeach</select></label>
                        <label>Capacity<input type="number" name="capacity" min="1" max="1000" value="{{ $table->capacity }}" required></label>
                        <label>Shape<select name="shape">@foreach(['round', 'square', 'rectangle'] as $shape)<option value="{{ $shape }}" @selected($table->shape === $shape)>{{ str($shape)->title() }}</option>@endforeach</select></label>
                        <label>Type<select name="type">@foreach(['standard', 'family', 'vip', 'outdoor', 'banquet'] as $type)<option value="{{ $type }}" @selected($table->type === $type)>{{ str($type)->title() }}</option>@endforeach</select></label>
                        <label>Sort order<input type="number" name="sort_order" min="0" value="{{ $table->sort_order }}"></label>
                        <label class="table-form-wide">Description<textarea name="description" rows="2">{{ $table->description }}</textarea></label>
                        <label class="table-checkbox"><input type="checkbox" name="is_active" value="1" @checked($table->is_active)> Active</label><button class="button button-primary">Save changes</button>
                    </form>
                </details>
                <a class="button button-tertiary" href="{{ route('restaurant.tables.show', $table) }}">Details</a>
                <form method="POST" action="{{ route('restaurant.tables.archive', $table) }}" onsubmit="return confirm('Archive this table? Historical records will remain.');">@csrf @method('DELETE')<button class="button button-danger" @disabled($table->status === 'occupied')>Archive</button></form>
            </div></td>
        </tr>
    @empty<tr><td colspan="6"><div class="empty-state"><h2>No matching tables</h2><p>Change filters or add your first table above.</p></div></td></tr>@endforelse</tbody>
</table></div>
<div class="table-pagination">{{ $tables->links() }}</div>
<script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>