@php
    $inventoryRoute = request()->route()->getName();
    $inventoryTabs = [];

    if (str_starts_with($inventoryRoute, 'restaurant.inventory.items.')
        || str_starts_with($inventoryRoute, 'restaurant.inventory.categories.')
        || str_starts_with($inventoryRoute, 'restaurant.inventory.units.')
        || str_starts_with($inventoryRoute, 'restaurant.inventory.locations.')) {
        $activeInventoryTab = match (true) {
            str_starts_with($inventoryRoute, 'restaurant.inventory.categories.') => 'categories',
            str_starts_with($inventoryRoute, 'restaurant.inventory.units.') => 'units',
            str_starts_with($inventoryRoute, 'restaurant.inventory.locations.') => 'locations',
            default => 'items',
        };
        $inventoryTabs = [
            ['key' => 'items', 'label' => 'Items', 'href' => route('restaurant.inventory.items.index')],
            ['key' => 'categories', 'label' => 'Categories', 'href' => route('restaurant.inventory.categories.index')],
            ['key' => 'units', 'label' => 'Units & Conversions', 'href' => route('restaurant.inventory.units.index')],
            ['key' => 'locations', 'label' => 'Stock Locations', 'href' => route('restaurant.inventory.locations.index')],
        ];
        $inventoryTabLabel = 'Inventory items';
    } elseif (str_starts_with($inventoryRoute, 'restaurant.inventory.stock.')) {
        $activeInventoryTab = request()->query('kind', 'opening');
        $inventoryTabs = [
            ['key' => 'opening', 'label' => 'Opening Stock', 'href' => route('restaurant.inventory.stock.create', ['kind' => 'opening'])],
            ['key' => 'in', 'label' => 'Stock In', 'href' => route('restaurant.inventory.stock.create', ['kind' => 'in'])],
            ['key' => 'out', 'label' => 'Stock Out', 'href' => route('restaurant.inventory.stock.create', ['kind' => 'out'])],
            ['key' => 'transfer', 'label' => 'Transfers', 'href' => route('restaurant.inventory.stock.create', ['kind' => 'transfer'])],
            ['key' => 'adjustment', 'label' => 'Adjustments', 'href' => route('restaurant.inventory.stock.create', ['kind' => 'adjustment'])],
        ];
        $inventoryTabLabel = 'Stock management';
    } elseif (str_starts_with($inventoryRoute, 'restaurant.inventory.counts.')
        || str_starts_with($inventoryRoute, 'restaurant.inventory.wastage.')
        || $inventoryRoute === 'restaurant.inventory.batches') {
        $activeInventoryTab = str_starts_with($inventoryRoute, 'restaurant.inventory.wastage.')
            ? 'wastage'
            : ($inventoryRoute === 'restaurant.inventory.batches' ? 'expiry' : 'counts');
        $inventoryTabs = [
            ['key' => 'counts', 'label' => 'Stock Counts', 'href' => route('restaurant.inventory.counts.index')],
            ['key' => 'wastage', 'label' => 'Wastage & Spoilage', 'href' => route('restaurant.inventory.wastage.index')],
            ['key' => 'expiry', 'label' => 'Expiry Tracking', 'href' => route('restaurant.inventory.batches')],
        ];
        $inventoryTabLabel = 'Stock control';
    } elseif (str_starts_with($inventoryRoute, 'restaurant.inventory.recipes.')
        || str_starts_with($inventoryRoute, 'restaurant.inventory.vendor-mappings.')) {
        $activeInventoryTab = str_starts_with($inventoryRoute, 'restaurant.inventory.vendor-mappings.')
            ? 'mapping'
            : 'recipes';
        $inventoryTabs = [
            ['key' => 'recipes', 'label' => 'Recipe Management', 'href' => route('restaurant.inventory.recipes.index')],
            ['key' => 'mapping', 'label' => 'Vendor Item Mapping', 'href' => route('restaurant.inventory.vendor-mappings.index')],
        ];
        $inventoryTabLabel = 'Recipes & mapping';
    } elseif (in_array($inventoryRoute, ['restaurant.inventory.ledger', 'restaurant.inventory.reports', 'restaurant.inventory.settings'], true)) {
        $activeInventoryTab = match ($inventoryRoute) {
            'restaurant.inventory.ledger' => 'ledger',
            'restaurant.inventory.settings' => 'settings',
            default => 'reports',
        };
        $inventoryTabs = [
            ['key' => 'ledger', 'label' => 'Stock Ledger', 'href' => route('restaurant.inventory.ledger')],
            ['key' => 'reports', 'label' => 'Inventory Reports', 'href' => route('restaurant.inventory.reports', request()->only(['report', 'date_from', 'date_to', 'item_id', 'category_id', 'location_id', 'movement_type', 'vendor_id']))],
            ['key' => 'settings', 'label' => 'Settings', 'href' => route('restaurant.inventory.settings')],
        ];
        $inventoryTabLabel = 'Reports & settings';
    }
@endphp

@if($inventoryTabs)
    <nav class="inventory-workspace-tabs" role="tablist" aria-label="{{ $inventoryTabLabel }}">
        @foreach($inventoryTabs as $tab)
            <a
                id="inventory-tab-{{ $tab['key'] }}"
                href="{{ $tab['href'] }}"
                role="tab"
                aria-selected="{{ $activeInventoryTab === $tab['key'] ? 'true' : 'false' }}"
                aria-current="{{ $activeInventoryTab === $tab['key'] ? 'page' : 'false' }}"
                tabindex="{{ $activeInventoryTab === $tab['key'] ? '0' : '-1' }}"
                @class(['is-active' => $activeInventoryTab === $tab['key']])
            >{{ $tab['label'] }}</a>
        @endforeach
    </nav>
@endif
