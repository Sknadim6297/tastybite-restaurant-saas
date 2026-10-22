@extends('layouts.restaurant-admin')

@section('title', 'Recipe Management')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Menu ingredient mapping</span><h1>Recipes</h1><p>Each saved recipe is versioned; completed POS orders retain a snapshot of the recipe used.</p></div></div>
    <section class="panel-card"><h2>Create recipe version</h2>
        <form method="POST" action="{{ route('restaurant.inventory.recipes.store') }}" class="form-stack">@csrf
            <div class="form-grid">
                <div class="form-field"><label for="menu_item_id">Menu product</label><select id="menu_item_id" name="menu_item_id" required><option value="">Choose menu product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
                <div class="form-field"><label for="yield">Yield (servings)</label><input id="yield" name="yield_quantity" type="number" min="0.000001" step="0.000001" value="1" required></div>
                <div class="form-field form-field-full"><label for="preparation_notes">Preparation notes</label><textarea id="preparation_notes" name="preparation_notes" rows="2"></textarea></div>
                <div class="form-field form-field-full"><label>Ingredients</label><div id="recipe-ingredient-rows" class="form-grid">
                    <div class="recipe-ingredient-row form-grid">
                        <div class="form-field"><label for="ingredient-item-0">Ingredient</label><select id="ingredient-item-0" name="ingredients[0][inventory_item_id]" required><option value="">Choose item</option>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select></div>
                        <div class="form-field"><label for="ingredient-unit-0">Ingredient unit</label><select id="ingredient-unit-0" name="ingredients[0][unit_id]" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>@endforeach</select></div>
                        <div class="form-field"><label for="ingredient-quantity-0">Quantity per recipe yield</label><input id="ingredient-quantity-0" name="ingredients[0][quantity]" type="number" min="0.000001" step="0.000001" required></div>
                    </div>
                </div><button class="button button-secondary" type="button" id="add-recipe-ingredient">Add ingredient</button></div>
                <template id="recipe-ingredient-template"><div class="recipe-ingredient-row form-grid">
                    <div class="form-field"><label for="ingredient-item-__INDEX__">Ingredient</label><select id="ingredient-item-__INDEX__" name="ingredients[__INDEX__][inventory_item_id]" required><option value="">Choose item</option>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select></div>
                    <div class="form-field"><label for="ingredient-unit-__INDEX__">Ingredient unit</label><select id="ingredient-unit-__INDEX__" name="ingredients[__INDEX__][unit_id]" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>@endforeach</select></div>
                    <div class="form-field"><label for="ingredient-quantity-__INDEX__">Quantity per recipe yield</label><input id="ingredient-quantity-__INDEX__" name="ingredients[__INDEX__][quantity]" type="number" min="0.000001" step="0.000001" required></div>
                    <button class="text-button remove-recipe-ingredient" type="button">Remove ingredient</button>
                </div></template>
            </div>
            @error('ingredients')<span class="field-error">{{ $message }}</span>@enderror
            <button class="button button-primary" type="submit">Save recipe version</button>
        </form>
    </section>
    <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Product</th><th>Version</th><th>Yield</th><th>Ingredients</th><th>Est. ingredient cost / serving</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @forelse($recipes as $recipe)
            <tr><td>{{ $recipe->menuItem->name }}</td><td>v{{ $recipe->version }}</td><td>{{ $recipe->yield_quantity }}</td><td>@foreach($recipe->ingredients as $line){{ $line->item->name }}: {{ $line->quantity }} {{ $line->unit->abbreviation }}@if(!$loop->last), @endif @endforeach</td><td>₹{{ \Brick\Math\BigDecimal::of($estimatedCosts[$recipe->id])->toScale(2, \Brick\Math\RoundingMode::HALF_UP) }}</td><td><span class="status-badge {{ $recipe->is_active ? 'status-badge-success' : 'status-badge-muted' }}">{{ $recipe->is_active ? 'Active' : 'Historical' }}</span></td><td>@unless($recipe->is_active)<form method="POST" action="{{ route('restaurant.inventory.recipes.activate', $recipe) }}">@csrf<button class="text-button" type="submit">Activate</button></form>@endunless</td></tr>
        @empty<tr><td colspan="7"><div class="empty-state"><h3>No recipes yet</h3><p>Add a recipe to link menu products with stock ingredients.</p></div></td></tr>@endforelse
    </tbody></table></div>@if($recipes->hasPages())<div class="page-tools pagination-tools">{{ $recipes->links() }}</div>@endif</section>
    <link rel="stylesheet" href="{{ asset('assets/restaurant-inventory.css') }}?v={{ filemtime(public_path('assets/restaurant-inventory.css')) }}">
    <script src="{{ asset('assets/restaurant-inventory.js') }}?v={{ filemtime(public_path('assets/restaurant-inventory.js')) }}" defer></script>
@endsection
