@extends('layouts.restaurant-admin')

@section('title', $title)
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Inventory setup</span><h1>{{ $title }}</h1><p>These settings and records belong to {{ $restaurant->name }}.</p></div></div>
    <div class="content-grid">
        <section class="panel-card">
            <h2>Add {{ $kind === 'categories' ? 'category' : ($kind === 'units' ? 'unit' : 'location') }}</h2>
            <form method="POST" action="{{ $kind === 'categories' ? route('restaurant.inventory.categories.store') : ($kind === 'units' ? route('restaurant.inventory.units.store') : route('restaurant.inventory.locations.store')) }}" class="form-stack">
                @csrf
                @if($kind === 'categories')
                    <div class="form-field"><label for="name">Category name</label><input id="name" name="name" maxlength="100" required></div>
                    <div class="form-field"><label for="description">Description</label><textarea id="description" name="description" rows="3"></textarea></div>
                    <input type="hidden" name="status" value="active">
                @elseif($kind === 'units')
                    <div class="form-field"><label for="name">Unit name</label><input id="name" name="name" maxlength="80" placeholder="Kilogram" required></div>
                    <div class="form-field"><label for="abbreviation">Abbreviation</label><input id="abbreviation" name="abbreviation" maxlength="16" placeholder="kg" required></div>
                    <div class="form-field"><label for="dimension">Dimension</label><input id="dimension" name="dimension" maxlength="32" pattern="[a-z][a-z0-9_]*" placeholder="mass" required><small>Compatible units share a dimension (for example, mass or volume).</small></div>
                @else
                    <div class="form-field"><label for="name">Location name</label><input id="name" name="name" maxlength="100" required></div>
                    <div class="form-field"><label for="code">Location code</label><input id="code" name="code" maxlength="24" pattern="[A-Za-z0-9_-]+" required></div>
                    <div class="form-field"><label for="description">Description</label><textarea id="description" name="description" rows="3"></textarea></div>
                @endif
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
                @error('abbreviation')<span class="field-error">{{ $message }}</span>@enderror
                @error('dimension')<span class="field-error">{{ $message }}</span>@enderror
                <button class="button button-primary" type="submit">Add record</button>
            </form>
        </section>

        @if($kind === 'units')
            <section class="panel-card"><h2>Add a unit conversion</h2>
                <form method="POST" action="{{ route('restaurant.inventory.units.conversions.store') }}" class="form-stack">@csrf
                    <div class="form-field"><label for="from-unit">From unit</label><select id="from-unit" name="from_unit_id" required>@foreach($records as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }}) · {{ $unit->dimension }}</option>@endforeach</select></div>
                    <div class="form-field"><label for="to-unit">To unit</label><select id="to-unit" name="to_unit_id" required>@foreach($records as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }}) · {{ $unit->dimension }}</option>@endforeach</select></div>
                    <div class="form-field"><label for="factor">Quantity multiplier</label><input id="factor" name="factor" type="number" min="0.000000001" step="0.000000001" required><small>Example: 1 kilogram equals 1000 grams, so enter 1000 for kg → g.</small></div>
                    @error('from_unit_id')<span class="field-error">{{ $message }}</span>@enderror
                    @error('to_unit_id')<span class="field-error">{{ $message }}</span>@enderror
                    @error('factor')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button button-primary" type="submit">Save conversion</button>
                </form>
            </section>
        @endif
    </div>

    @if($kind === 'units' && isset($conversions))
        <section class="panel-card"><h2>Configured conversions</h2><div class="table-shell"><table class="data-table"><thead><tr><th>From</th><th>To</th><th>Multiplier</th></tr></thead><tbody>
            @forelse($conversions as $conversion)<tr><td>{{ $conversion->from_name }}</td><td>{{ $conversion->to_name }}</td><td>{{ $conversion->factor }}</td></tr>@empty<tr><td colspan="3"><div class="empty-state"><h3>No conversions configured</h3><p>Conversions are only applied to units with compatible dimensions.</p></div></td></tr>@endforelse
        </tbody></table></div></section>
    @endif

    <section class="panel-card"><div class="table-shell"><table class="data-table">
        <thead>@if($kind === 'categories')<tr><th>Name</th><th>Description</th><th>Items</th><th>Status</th><th>Actions</th></tr>@elseif($kind === 'units')<tr><th>Name</th><th>Abbreviation</th><th>Dimension</th><th>Items</th><th>Action</th></tr>@else<tr><th>Name</th><th>Code</th><th>Description</th><th>Status</th><th>Actions</th></tr>@endif</thead>
        <tbody>
            @forelse($records as $record)
                @if($kind === 'categories')
                    <tr><td colspan="5"><form method="POST" action="{{ route('restaurant.inventory.categories.update', $record) }}" class="inline-form">@csrf @method('PUT')<input name="name" value="{{ $record->name }}" maxlength="100" required><input name="description" value="{{ $record->description }}" maxlength="2000" placeholder="Description"><span>{{ $record->items_count }} items</span><select name="status"><option value="active" @selected($record->status === 'active')>Active</option><option value="inactive" @selected($record->status === 'inactive')>Inactive</option></select><button class="text-button" type="submit">Save</button></form>
                        @if($record->items_count === 0)<form method="POST" action="{{ route('restaurant.inventory.categories.delete', $record) }}" data-confirm="Delete this category?">@csrf @method('DELETE')<button class="text-button danger-text" type="submit">Delete</button></form>@endif
                    </td></tr>
                @elseif($kind === 'units')
                    <tr><td>{{ $record->name }}</td><td>{{ $record->abbreviation }}</td><td>{{ $record->dimension }}</td><td>{{ $record->items_count }}</td><td>@if($record->items_count === 0)<form method="POST" action="{{ route('restaurant.inventory.units.delete', $record) }}" data-confirm="Delete this unit?">@csrf @method('DELETE')<button class="text-button danger-text" type="submit">Delete</button></form>@else<span class="meta-label">In use</span>@endif</td></tr>
                @else
                    <tr><td colspan="5"><form method="POST" action="{{ route('restaurant.inventory.locations.update', $record) }}" class="inline-form">@csrf @method('PUT')<input name="name" value="{{ $record->name }}" maxlength="100" required><input name="code" value="{{ $record->code }}" maxlength="24" required><input name="description" value="{{ $record->description }}" maxlength="2000" placeholder="Description"><select name="status"><option value="active" @selected($record->status === 'active')>Active</option><option value="inactive" @selected($record->status === 'inactive')>Inactive</option></select><button class="text-button" type="submit">Save</button></form>
                        @if($record->balances_count === 0)<form method="POST" action="{{ route('restaurant.inventory.locations.delete', $record) }}" data-confirm="Delete this location?">@csrf @method('DELETE')<button class="text-button danger-text" type="submit">Delete</button></form>@endif
                    </td></tr>
                @endif
            @empty<tr><td colspan="5"><div class="empty-state"><h3>No records yet</h3><p>Add a {{ rtrim(strtolower($title), 's') }} to get started.</p></div></td></tr>@endforelse
        </tbody>
    </table></div>
    @if($records->hasPages())<div class="page-tools pagination-tools">{{ $records->links() }}</div>@endif</section>
@endsection
