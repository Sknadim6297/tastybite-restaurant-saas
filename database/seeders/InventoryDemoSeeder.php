<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryUnit;
use App\Models\MenuRecipe;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPurchaseOrder;
use App\Services\InventoryStockService;
use App\Services\VendorProcurementService;
use Brick\Math\BigDecimal;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryDemoSeeder extends Seeder
{
    private const REQUIRED_TABLES = [
        'restaurants',
        'users',
        'vendors',
        'vendor_purchase_orders',
        'vendor_purchase_order_items',
        'restaurant_menu_items',
        'inventory_units',
        'inventory_unit_conversions',
        'inventory_categories',
        'inventory_locations',
        'inventory_items',
        'inventory_settings',
        'inventory_stock_balances',
        'inventory_item_batches',
        'inventory_movements',
        'inventory_stock_transfers',
        'inventory_adjustments',
        'inventory_stock_counts',
        'inventory_stock_count_items',
        'inventory_wastage_records',
        'menu_recipes',
        'menu_recipe_ingredients',
        'inventory_vendor_item_mappings',
    ];

    private const CATEGORY_NAMES = [
        'Rice', 'Vegetables', 'Meat', 'Fish', 'Dairy', 'Spices',
        'Oils', 'Flour', 'Beverages', 'Packaging', 'Frozen Foods', 'Others',
    ];

    private const LOCATIONS = [
        ['Main Kitchen', 'MAIN-KIT'],
        ['Dry Store', 'DRY-STORE'],
        ['Cold Storage', 'COLD-STOR'],
        ['Freezer', 'FREEZER'],
        ['Beverage Store', 'BEVERAGE'],
    ];

    private const UNIT_DEFINITIONS = [
        ['Kilogram', 'kg', 'mass'],
        ['Gram', 'g', 'mass'],
        ['Litre', 'L', 'volume'],
        ['Millilitre', 'ml', 'volume'],
        ['Piece', 'piece', 'count'],
        ['Packet', 'packet', 'package'],
        ['Dozen', 'dozen', 'count'],
    ];

    private const SUPPLIERS = [
        'produce' => ['name' => 'Inventory Demo Fresh Produce Supplier', 'category' => 'Produce'],
        'protein' => ['name' => 'Inventory Demo Meat & Seafood Supplier', 'category' => 'Poultry & Meat'],
        'dairy' => ['name' => 'Inventory Demo Dairy Supplier', 'category' => 'Dairy'],
        'provisions' => ['name' => 'Inventory Demo Grocery & Spice Supplier', 'category' => 'Grains & Pulses'],
        'packaging' => ['name' => 'Inventory Demo Packaging Supplier', 'category' => 'Packaging'],
    ];

    private const ITEMS = [
        ['Basmati Rice', 'Rice', 'mass', 'Dry Store', 'provisions', '120.00', 3.0, 30.0, 15.0, 12.0],
        ['Sona Masoori Rice', 'Rice', 'mass', 'Dry Store', 'provisions', '78.00', 2.0, 24.0, 12.0, 8.0],
        ['Potato', 'Vegetables', 'mass', 'Main Kitchen', 'produce', '28.00', 2.0, 20.0, 8.0, 10.0],
        ['Onion', 'Vegetables', 'mass', 'Main Kitchen', 'produce', '32.00', 2.0, 18.0, 8.0, 8.0],
        ['Tomato', 'Vegetables', 'mass', 'Main Kitchen', 'produce', '36.00', 3.0, 16.0, 8.0, 6.0],
        ['Green Chilli', 'Vegetables', 'mass', 'Main Kitchen', 'produce', '72.00', 1.0, 5.0, 2.0, 2.0],
        ['Coriander Leaves', 'Vegetables', 'mass', 'Main Kitchen', 'produce', '90.00', 0.5, 4.0, 1.5, 1.5],
        ['Chicken', 'Meat', 'mass', 'Cold Storage', 'protein', '245.00', 2.0, 18.0, 8.0, 7.0],
        ['Chicken Breast', 'Meat', 'mass', 'Cold Storage', 'protein', '320.00', 1.5, 12.0, 5.0, 4.0],
        ['Mutton', 'Meat', 'mass', 'Freezer', 'protein', '760.00', 1.0, 10.0, 5.0, 3.0],
        ['Eggs', 'Meat', 'count', 'Cold Storage', 'protein', '7.00', 24.0, 120.0, 48.0, 48.0],
        ['Rohu Fish', 'Fish', 'mass', 'Cold Storage', 'protein', '280.00', 1.5, 10.0, 4.0, 3.0],
        ['Prawns', 'Fish', 'mass', 'Freezer', 'protein', '520.00', 2.0, 3.0, 1.0, 3.5],
        ['Pomfret', 'Fish', 'mass', 'Freezer', 'protein', '680.00', 1.0, 8.0, 3.0, 2.5],
        ['Full Cream Milk', 'Dairy', 'volume', 'Cold Storage', 'dairy', '62.00', 4.0, 24.0, 10.0, 10.0],
        ['Paneer', 'Dairy', 'mass', 'Cold Storage', 'dairy', '360.00', 2.0, 12.0, 5.0, 4.0],
        ['Butter', 'Dairy', 'mass', 'Cold Storage', 'dairy', '520.00', 1.0, 8.0, 3.0, 3.0],
        ['Fresh Cream', 'Dairy', 'volume', 'Cold Storage', 'dairy', '240.00', 1.0, 4.0, 1.0, 2.5],
        ['Yogurt', 'Dairy', 'mass', 'Cold Storage', 'dairy', '90.00', 2.0, 10.0, 4.0, 3.0],
        ['Turmeric Powder', 'Spices', 'mass', 'Dry Store', 'provisions', '240.00', 0.5, 4.0, 2.0, 1.0],
        ['Red Chilli Powder', 'Spices', 'mass', 'Dry Store', 'provisions', '340.00', 0.5, 4.0, 2.0, 1.0],
        ['Cumin Seeds', 'Spices', 'mass', 'Dry Store', 'provisions', '460.00', 0.3, 2.0, 1.0, 0.5],
        ['Coriander Powder', 'Spices', 'mass', 'Dry Store', 'provisions', '280.00', 0.5, 3.0, 1.5, 0.8],
        ['Garam Masala', 'Spices', 'mass', 'Dry Store', 'provisions', '620.00', 0.3, 2.0, 1.0, 0.5],
        ['Biryani Masala', 'Spices', 'mass', 'Dry Store', 'provisions', '560.00', 0.3, 2.0, 1.0, 0.5],
        ['Ginger Garlic Paste', 'Spices', 'mass', 'Cold Storage', 'produce', '110.00', 1.0, 6.0, 2.0, 2.0],
        ['Sunflower Cooking Oil', 'Oils', 'volume', 'Dry Store', 'provisions', '155.00', 2.0, 20.0, 8.0, 14.0],
        ['Groundnut Oil', 'Oils', 'volume', 'Dry Store', 'provisions', '205.00', 1.0, 12.0, 5.0, 5.0],
        ['Whole Wheat Flour', 'Flour', 'mass', 'Dry Store', 'provisions', '44.00', 2.0, 20.0, 8.0, 8.0],
        ['Maida', 'Flour', 'mass', 'Dry Store', 'provisions', '42.00', 2.0, 18.0, 7.0, 7.0],
        ['Besan', 'Flour', 'mass', 'Dry Store', 'provisions', '98.00', 1.0, 10.0, 4.0, 4.0],
        ['Tea Leaves', 'Beverages', 'mass', 'Beverage Store', 'provisions', '420.00', 0.5, 5.0, 2.0, 2.0],
        ['Coffee Beans', 'Beverages', 'mass', 'Beverage Store', 'provisions', '780.00', 0.3, 3.0, 1.0, 1.0],
        ['Mango Drink Concentrate', 'Beverages', 'volume', 'Beverage Store', 'provisions', '125.00', 2.0, 12.0, 5.0, 5.0],
        ['Kraft Takeaway Container', 'Packaging', 'package', 'Dry Store', 'packaging', '6.50', 50.0, 400.0, 150.0, 150.0],
        ['Paper Cup', 'Packaging', 'package', 'Beverage Store', 'packaging', '2.00', 50.0, 300.0, 100.0, 100.0],
        ['Frozen Green Peas', 'Frozen Foods', 'mass', 'Freezer', 'produce', '145.00', 1.0, 8.0, 3.0, 3.0],
        ['Frozen French Fries', 'Frozen Foods', 'mass', 'Freezer', 'produce', '180.00', 1.0, 10.0, 4.0, 4.0],
        ['Iodized Salt', 'Others', 'mass', 'Dry Store', 'provisions', '22.00', 1.0, 12.0, 5.0, 5.0],
        ['White Sugar', 'Others', 'mass', 'Dry Store', 'provisions', '46.00', 1.0, 12.0, 5.0, 5.0],
    ];

    private const TRACKED_ITEMS = [
        'Tomato', 'Chicken', 'Chicken Breast', 'Mutton', 'Rohu Fish', 'Prawns', 'Pomfret',
        'Full Cream Milk', 'Paneer', 'Fresh Cream', 'Yogurt', 'Frozen Green Peas', 'Frozen French Fries',
    ];

    private const SOON_EXPIRING_ITEMS = ['Tomato', 'Chicken', 'Full Cream Milk', 'Paneer'];

    private const RECIPE_DEFINITIONS = [
        'Chicken Biryani' => [
            'Basmati Rice' => '0.250000',
            'Chicken' => '0.250000',
            'Onion' => '0.080000',
            'Tomato' => '0.050000',
            'Yogurt' => '0.050000',
            'Ginger Garlic Paste' => '0.020000',
            'Biryani Masala' => '0.008000',
            'Sunflower Cooking Oil' => '0.030000',
        ],
        'Mutton Biryani' => [
            'Basmati Rice' => '0.250000',
            'Mutton' => '0.250000',
            'Onion' => '0.100000',
            'Yogurt' => '0.060000',
            'Ginger Garlic Paste' => '0.025000',
            'Biryani Masala' => '0.010000',
            'Cumin Seeds' => '0.003000',
            'Sunflower Cooking Oil' => '0.030000',
        ],
        'Paneer Butter Masala' => [
            'Paneer' => '0.200000',
            'Tomato' => '0.180000',
            'Fresh Cream' => '0.040000',
            'Butter' => '0.025000',
            'Ginger Garlic Paste' => '0.015000',
            'Red Chilli Powder' => '0.003000',
            'Garam Masala' => '0.002000',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('InventoryDemoSeeder is restricted to APP_ENV=local.');
        }

        $missingTables = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));
        if ($missingTables !== []) {
            throw new RuntimeException(
                'Inventory demo data requires these existing tables: '.implode(', ', $missingTables)
                .'. Apply the project migrations manually in a local database, then rerun this seeder.',
            );
        }

        $requiredSettingsColumns = [
            'restaurant_id',
            'default_location_id',
            'expiry_warning_days',
            'deduction_event',
            'allow_negative_stock',
            'valuation_method',
            'consume_recipes',
            'low_stock_alerts_enabled',
            'expiry_alerts_enabled',
            'created_at',
            'updated_at',
        ];
        $missingSettingsColumns = array_values(array_filter(
            $requiredSettingsColumns,
            static fn (string $column): bool => ! Schema::hasColumn('inventory_settings', $column),
        ));
        if ($missingSettingsColumns !== []) {
            throw new RuntimeException(
                'The inventory_settings table is missing required columns: '.implode(', ', $missingSettingsColumns)
                .'. Run php artisan migrate in the local environment, then rerun this seeder.',
            );
        }

        $restaurant = Restaurant::query()->where('name', 'Test resturent')->sole();
        $owner = $restaurant->owners()->orderBy('id')->first();
        if (! $owner) {
            throw new RuntimeException('The "Test resturent" restaurant has no owner account to record inventory transactions.');
        }

        DB::transaction(function () use ($restaurant, $owner): void {
            $faker = Factory::create('en_IN');
            $faker->seed(20261022);
            $categories = $this->seedCategories($restaurant);
            $units = $this->seedUnits($restaurant);
            $locations = $this->seedLocations($restaurant);
            $vendors = $this->seedVendors($restaurant, $faker);
            $items = $this->seedItems($restaurant, $categories, $units, $locations, $vendors);

            $this->seedSettings($restaurant, $locations['Main Kitchen']);
            $this->seedMovements($restaurant, $owner, $items, $locations);
            $this->seedStockControl($restaurant, $owner, $items, $locations);
            $this->seedRecipes($restaurant, $items, $units);
            $this->seedVendorMappings($restaurant, $owner, $items, $vendors, $units, $locations);
        });
    }

    /**
     * @return array<string, InventoryCategory>
     */
    private function seedCategories(Restaurant $restaurant): array
    {
        $categories = [];
        foreach (self::CATEGORY_NAMES as $name) {
            $category = InventoryCategory::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $name,
            ]);
            if (! $category->exists) {
                $category->description = 'Inventory demo category: '.$name;
                $category->status = 'active';
                $category->save();
            } elseif ($category->status !== 'active') {
                $category->update(['status' => 'active']);
            }
            $categories[$name] = $category;
        }

        return $categories;
    }

    /**
     * @return array<string, InventoryUnit>
     */
    private function seedUnits(Restaurant $restaurant): array
    {
        $units = [];
        foreach (self::UNIT_DEFINITIONS as [$name, $abbreviation, $dimension]) {
            $unit = InventoryUnit::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'abbreviation' => $abbreviation,
            ]);
            if ($unit->exists && $unit->dimension !== $dimension) {
                throw new RuntimeException("Unit {$abbreviation} already exists with the incompatible {$unit->dimension} dimension.");
            }
            if (! $unit->exists) {
                $unit->name = $name;
                $unit->dimension = $dimension;
                $unit->status = 'active';
                $unit->save();
            } elseif ($unit->status !== 'active') {
                $unit->update(['status' => 'active']);
            }
            $units[$abbreviation] = $unit;
        }

        foreach ([
            ['kg', 'g', '1000.000000000'],
            ['L', 'ml', '1000.000000000'],
            ['dozen', 'piece', '12.000000000'],
        ] as [$from, $to, $factor]) {
            $conversion = DB::table('inventory_unit_conversions')
                ->where('restaurant_id', $restaurant->id)
                ->where('from_unit_id', $units[$from]->id)
                ->where('to_unit_id', $units[$to]->id)
                ->first();
            if ($conversion && BigDecimal::of((string) $conversion->factor)->compareTo($factor) !== 0) {
                throw new RuntimeException("The existing {$from} to {$to} unit conversion conflicts with the expected demo conversion.");
            }
            if (! $conversion) {
                DB::table('inventory_unit_conversions')->insert([
                    'restaurant_id' => $restaurant->id,
                    'from_unit_id' => $units[$from]->id,
                    'to_unit_id' => $units[$to]->id,
                    'factor' => $factor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $units;
    }

    /**
     * @return array<string, InventoryLocation>
     */
    private function seedLocations(Restaurant $restaurant): array
    {
        $locations = [];
        foreach (self::LOCATIONS as [$name, $code]) {
            $location = InventoryLocation::query()
                ->where('restaurant_id', $restaurant->id)->where('name', $name)->first();
            if (! $location) {
                $codeInUse = InventoryLocation::query()
                    ->where('restaurant_id', $restaurant->id)->where('code', $code)->exists();
                if ($codeInUse) {
                    throw new RuntimeException("Inventory demo location code {$code} is already assigned to a different location.");
                }
                $location = InventoryLocation::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $name,
                    'code' => $code,
                    'description' => 'Inventory demo stock location.',
                    'status' => 'active',
                ]);
            } elseif ($location->status !== 'active') {
                $location->update(['status' => 'active']);
            }
            $locations[$name] = $location;
        }

        return $locations;
    }

    /**
     * @return array<string, Vendor>
     */
    private function seedVendors(Restaurant $restaurant, Generator $faker): array
    {
        $vendors = [];
        foreach (self::SUPPLIERS as $key => $supplier) {
            $pan = $faker->regexify('[A-Z]{5}[0-9]{4}[A-Z]');
            $vendor = Vendor::withTrashed()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'vendor_code' => 'INV-DEMO-VND-'.Str::upper($key),
            ]);
            $vendor->fill([
                'name' => $supplier['name'],
                'contact_person' => $faker->name(),
                'mobile' => '+919'.str_pad((string) (700000000 + count($vendors)), 9, '0', STR_PAD_LEFT),
                'email' => 'inventory.'.strtolower($key).'@example.test',
                'category' => $supplier['category'],
                'status' => 'active',
                'business_name' => $supplier['name'],
                'gstin' => '29'.$pan.'1Z'.count($vendors),
                'pan_number' => $pan,
                'payment_terms_days' => 15,
                'credit_limit' => '250000.00',
                'address_line_1' => $faker->streetAddress(),
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'postal_code' => $faker->numerify('5#####'),
                'country' => 'India',
                'notes' => 'Local inventory demo supplier.',
            ]);
            $vendor->save();
            if ($vendor->trashed()) {
                $vendor->restore();
            }
            $vendors[$key] = $vendor;
        }

        return $vendors;
    }

    /**
     * @param  array<string, InventoryCategory>  $categories
     * @param  array<string, InventoryUnit>  $units
     * @param  array<string, InventoryLocation>  $locations
     * @param  array<string, Vendor>  $vendors
     * @return array<string, InventoryItem>
     */
    private function seedItems(
        Restaurant $restaurant,
        array $categories,
        array $units,
        array $locations,
        array $vendors,
    ): array {
        $items = [];
        foreach (self::ITEMS as $index => $definition) {
            [$name, $category, $unitType, $location, $vendorKey, $cost, $reorder, $opening, $receipt, $issue] = $definition;
            $itemCode = sprintf('DEMO-INV-%04d', $index + 1);
            $existingByName = InventoryItem::query()->where('restaurant_id', $restaurant->id)->where('name', $name)->first();
            if ($existingByName && $existingByName->item_code !== $itemCode) {
                throw new RuntimeException(
                    "Cannot seed {$name}: a different inventory item already uses that name in Test resturent. "
                    .'Review the existing item before rerunning to avoid duplicate or overwritten records.',
                );
            }

            $baseUnit = match ($unitType) {
                'mass' => $units['kg'],
                'volume' => $units['L'],
                'count' => $units['piece'],
                'package' => $units['packet'],
            };
            $purchaseUnit = match ([$name, $unitType]) {
                ['Eggs', 'count'] => $units['dozen'],
                ['Turmeric Powder', 'mass'], ['Red Chilli Powder', 'mass'],
                ['Cumin Seeds', 'mass'], ['Coriander Powder', 'mass'],
                ['Garam Masala', 'mass'], ['Biryani Masala', 'mass'] => $units['g'],
                ['Full Cream Milk', 'volume'], ['Fresh Cream', 'volume'] => $units['ml'],
                default => $baseUnit,
            };
            $consumptionUnit = match ($unitType) {
                'mass' => $units['g'],
                'volume' => $units['ml'],
                'count' => $units['piece'],
                'package' => $units['packet'],
            };
            $tracked = in_array($name, self::TRACKED_ITEMS, true);
            $item = InventoryItem::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'item_code' => $itemCode],
                [
                    'category_id' => $categories[$category]->id,
                    'base_unit_id' => $baseUnit->id,
                    'purchase_unit_id' => $purchaseUnit->id,
                    'consumption_unit_id' => $consumptionUnit->id,
                    'preferred_vendor_id' => $vendors[$vendorKey]->id,
                    'default_location_id' => $locations[$location]->id,
                    'name' => $name,
                    'sku' => sprintf('DEMO-SKU-%04d', $index + 1),
                    'description' => 'Local demo stock item for restaurant operations.',
                    'reorder_level' => number_format((float) $reorder, 6, '.', ''),
                    'reorder_quantity' => number_format(max((float) $reorder * 2, 1), 6, '.', ''),
                    'standard_cost' => number_format((float) $cost, 6, '.', ''),
                    'tax_rate' => '5.00',
                    'track_expiry' => $tracked,
                    'track_batches' => $tracked,
                    'status' => 'active',
                    'notes' => 'Seeded by InventoryDemoSeeder; safe to rerun.',
                ],
            );

            $items[$name] = $item;
        }

        return $items;
    }

    private function seedSettings(Restaurant $restaurant, InventoryLocation $defaultLocation): void
    {
        DB::table('inventory_settings')->insertOrIgnore([
            'restaurant_id' => $restaurant->id,
            'default_location_id' => $defaultLocation->id,
            'expiry_warning_days' => 30,
            'deduction_event' => 'sale_completed',
            'allow_negative_stock' => false,
            'valuation_method' => 'weighted_average',
            'consume_recipes' => true,
            'low_stock_alerts_enabled' => true,
            'expiry_alerts_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, InventoryItem>  $items
     * @param  array<string, InventoryLocation>  $locations
     * @param  array<string, InventoryUnit>  $units
     */
    private function seedMovements(
        Restaurant $restaurant,
        User $owner,
        array $items,
        array $locations,
    ): void {
        $stock = app(InventoryStockService::class);
        foreach (self::ITEMS as $index => $definition) {
            [$name, , , $locationName, , $cost, , $opening, $receipt, $issue] = $definition;
            $item = $items[$name];
            $location = $locations[$locationName];
            $date = now()->subDays(14 - ($index % 12))->setTime(7, 30);

            $this->receiveSeeded(
                $stock, $owner, $item, $location, (string) $opening, (string) $cost,
                'opening', 'inventory-demo:opening:'.sprintf('%04d', $index + 1),
                'OPEN-'.sprintf('%04d', $index + 1), $date->toDateTimeString(),
            );
            $this->receiveSeeded(
                $stock, $owner, $item, $location, (string) $receipt, (string) $cost,
                'manual_receipt', 'inventory-demo:receipt:'.sprintf('%04d', $index + 1),
                'RCPT-'.sprintf('%04d', $index + 1), $date->addDays(3)->toDateTimeString(),
            );

            $stock->issue(
                $owner,
                $item,
                $location,
                (string) $issue,
                'manual_issue',
                'inventory-demo:issue:'.sprintf('%04d', $index + 1),
                [
                    'source_type' => 'inventory_demo_issue',
                    'reference' => 'DEMO-ISSUE-'.sprintf('%04d', $index + 1),
                    'reason' => 'preparation',
                    'occurred_at' => $date->addDays(4)->toDateTimeString(),
                    'notes' => 'Local inventory demo kitchen issue.',
                ],
            );
        }

        $potato = $items['Potato'];
        $stock->transfer(
            $owner, $potato, $locations['Main Kitchen'], $locations['Dry Store'], '1.000000',
            'inventory-demo:transfer:potato:main-dry',
            now()->subDays(5)->setTime(11, 0)->toDateTimeString(),
            'Local inventory demo inter-store transfer.',
        );

        $rice = $items['Sona Masoori Rice'];
        $riceLocation = $locations['Dry Store'];
        $riceBalance = (string) DB::table('inventory_stock_balances')
            ->where('restaurant_id', $restaurant->id)->where('inventory_item_id', $rice->id)
            ->where('location_id', $riceLocation->id)->value('quantity');
        $stock->adjust(
            $owner, $rice, $riceLocation, (string) ((float) $riceBalance + 1),
            'data_correction', 'inventory-demo:adjustment:sona-rice',
            now()->subDays(2)->setTime(15, 0)->toDateTimeString(),
            'Local inventory demo stock reconciliation.',
        );
    }

    private function receiveSeeded(
        InventoryStockService $stock,
        User $owner,
        InventoryItem $item,
        InventoryLocation $location,
        string $quantity,
        string $unitCost,
        string $movementType,
        string $idempotencyKey,
        string $batchSuffix,
        string $occurredAt,
    ): void {
        $batchId = null;
        $tracked = $item->track_batches || $item->track_expiry;
        if ($tracked) {
            $expiresAt = match (true) {
                $item->name === 'Full Cream Milk' && str_starts_with($batchSuffix, 'RCPT-') => today()->addDays(14)->toDateString(),
                in_array($item->name, self::SOON_EXPIRING_ITEMS, true) && str_starts_with($batchSuffix, 'RCPT-') => today()->addDays(9)->toDateString(),
                default => today()->addDays(75)->toDateString(),
            };
            $batch = DB::table('inventory_item_batches')
                ->where('restaurant_id', $item->restaurant_id)->where('inventory_item_id', $item->id)
                ->where('location_id', $location->id)->where('batch_number', $batchSuffix)->first();
            if ($batch && ($batch->expires_at !== $expiresAt || (int) $batch->vendor_id !== (int) $item->preferred_vendor_id)) {
                throw new RuntimeException("Seeded batch {$batchSuffix} conflicts with existing batch details.");
            }
            if (! $batch) {
                $batchId = DB::table('inventory_item_batches')->insertGetId([
                    'restaurant_id' => $item->restaurant_id,
                    'inventory_item_id' => $item->id,
                    'location_id' => $location->id,
                    'vendor_id' => $item->preferred_vendor_id,
                    'batch_number' => $batchSuffix,
                    'manufactured_at' => today()->subDays(20)->toDateString(),
                    'expires_at' => $expiresAt,
                    'received_quantity' => '0.000000',
                    'available_quantity' => '0.000000',
                    'unit_cost' => $unitCost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $batchId = (int) $batch->id;
            }
        }

        $alreadyPosted = DB::table('inventory_movements')->where('restaurant_id', $item->restaurant_id)
            ->where('idempotency_key', $idempotencyKey)->exists();
        $stock->receive(
            $owner,
            $item,
            $location,
            $quantity,
            $unitCost,
            $movementType,
            $idempotencyKey,
            [
                'source_type' => 'inventory_demo_'.$movementType,
                'reference' => 'DEMO-'.$batchSuffix,
                'occurred_at' => $occurredAt,
                'notes' => 'Local inventory demo stock posting.',
                ...($batchId ? ['batch_id' => $batchId] : []),
            ],
        );
        if ($batchId && ! $alreadyPosted) {
            DB::table('inventory_item_batches')->where('id', $batchId)->update([
                'received_quantity' => $quantity,
                'unit_cost' => $unitCost,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, InventoryItem>  $items
     * @param  array<string, InventoryLocation>  $locations
     */
    private function seedStockControl(
        Restaurant $restaurant,
        User $owner,
        array $items,
        array $locations,
    ): void {
        $wastageKey = 'inventory-demo:wastage:onion';
        $wastage = DB::table('inventory_wastage_records')->where('restaurant_id', $restaurant->id)
            ->where('idempotency_key', $wastageKey)->first();
        if (! $wastage) {
            $item = $items['Onion'];
            $location = $locations['Main Kitchen'];
            $wastageId = DB::table('inventory_wastage_records')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'recorded_by' => $owner->id,
                'wastage_number' => 'TMP-'.Str::uuid(),
                'quantity' => '0.250000',
                'cost' => '0.000000',
                'reason' => 'preparation_waste',
                'idempotency_key' => $wastageKey,
                'wasted_at' => now()->subDay()->setTime(16, 0),
                'notes' => 'Local inventory demo preparation wastage.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wastageNumber = sprintf('WST%06d', $wastageId);
            DB::table('inventory_wastage_records')->where('id', $wastageId)->update(['wastage_number' => $wastageNumber]);
            $movements = app(InventoryStockService::class)->issue(
                $owner, $item, $location, '0.250000', 'wastage', 'wastage:'.$wastageKey,
                [
                    'source_type' => 'wastage',
                    'source_id' => $wastageId,
                    'reference' => $wastageNumber,
                    'reason' => 'preparation_waste',
                    'occurred_at' => now()->subDay()->setTime(16, 0)->toDateTimeString(),
                    'notes' => 'Local inventory demo preparation wastage.',
                ],
            );
            $cost = BigDecimal::zero();
            foreach ($movements as $movement) {
                $cost = $cost->plus(BigDecimal::of((string) $movement->value)->abs());
            }
            DB::table('inventory_wastage_records')->where('id', $wastageId)->update([
                'movement_id' => $movements[0]->id,
                'cost' => $cost->toScale(6)->__toString(),
                'updated_at' => now(),
            ]);
        }

        $countKey = 'inventory-demo:count:main-kitchen';
        $count = DB::table('inventory_stock_counts')->where('restaurant_id', $restaurant->id)
            ->where('idempotency_key', $countKey)->first();
        if (! $count) {
            $location = $locations['Main Kitchen'];
            $countId = DB::table('inventory_stock_counts')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'location_id' => $location->id,
                'created_by' => $owner->id,
                'posted_by' => $owner->id,
                'count_number' => 'TMP-'.Str::uuid(),
                'status' => 'posted',
                'idempotency_key' => $countKey,
                'counted_at' => now()->subHours(12),
                'posted_at' => now()->subHours(10),
                'notes' => 'Local demo physical count; quantities match balances at count time.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('inventory_stock_counts')->where('id', $countId)
                ->update(['count_number' => sprintf('CNT%06d', $countId)]);
            foreach ($items as $item) {
                $quantity = DB::table('inventory_stock_balances')->where('restaurant_id', $restaurant->id)
                    ->where('inventory_item_id', $item->id)->where('location_id', $location->id)->value('quantity') ?? '0';
                DB::table('inventory_stock_count_items')->insert([
                    'restaurant_id' => $restaurant->id,
                    'stock_count_id' => $countId,
                    'inventory_item_id' => $item->id,
                    'expected_quantity' => $quantity,
                    'actual_quantity' => $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, InventoryItem>  $items
     * @param  array<string, InventoryUnit>  $units
     */
    private function seedRecipes(Restaurant $restaurant, array $items, array $units): void
    {
        $missingProducts = [];
        foreach (self::RECIPE_DEFINITIONS as $productName => $ingredients) {
            $menuItem = RestaurantMenuItem::query()->where('restaurant_id', $restaurant->id)
                ->where('name', $productName)->orderBy('id')->first();
            if (! $menuItem) {
                $missingProducts[] = $productName;

                continue;
            }

            $marker = 'InventoryDemoSeeder sample recipe';
            $recipe = MenuRecipe::query()->where('restaurant_id', $restaurant->id)
                ->where('menu_item_id', $menuItem->id)->where('preparation_notes', $marker)->first();
            if (! $recipe) {
                $version = (int) MenuRecipe::query()->where('restaurant_id', $restaurant->id)
                    ->where('menu_item_id', $menuItem->id)->max('version') + 1;
                $recipe = MenuRecipe::create([
                    'restaurant_id' => $restaurant->id,
                    'menu_item_id' => $menuItem->id,
                    'version' => $version,
                    'yield_quantity' => '1.000000',
                    'is_active' => true,
                    'preparation_notes' => $marker,
                ]);
            }

            foreach ($ingredients as $itemName => $baseQuantity) {
                $item = $items[$itemName];
                $consumptionUnit = InventoryUnit::query()->where('restaurant_id', $restaurant->id)
                    ->findOrFail($item->consumption_unit_id);
                $quantity = app(InventoryStockService::class)->convertQuantity(
                    (int) $restaurant->id,
                    $baseQuantity,
                    (int) $item->base_unit_id,
                    (int) $consumptionUnit->id,
                );
                DB::table('menu_recipe_ingredients')->updateOrInsert(
                    ['menu_recipe_id' => $recipe->id, 'inventory_item_id' => $item->id],
                    [
                        'restaurant_id' => $restaurant->id,
                        'unit_id' => $consumptionUnit->id,
                        'quantity' => $quantity,
                        'base_quantity' => $baseQuantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        if ($missingProducts !== []) {
            $message = 'Inventory demo recipes were not created because these menu products do not exist for Test resturent: '
                .implode(', ', $missingProducts).'. Add the menu products and rerun InventoryDemoSeeder.';
            if ($this->command) {
                $this->command->warn($message);
            } else {
                report(new RuntimeException($message));
            }
        }
    }

    /**
     * @param  array<string, InventoryItem>  $items
     * @param  array<string, Vendor>  $vendors
     * @param  array<string, InventoryUnit>  $units
     * @param  array<string, InventoryLocation>  $locations
     */
    private function seedVendorMappings(
        Restaurant $restaurant,
        User $owner,
        array $items,
        array $vendors,
        array $units,
        array $locations,
    ): void {
        $procurement = app(VendorProcurementService::class);
        foreach (self::SUPPLIERS as $key => $supplier) {
            $vendor = $vendors[$key];
            $vendorItems = array_filter(self::ITEMS, fn (array $definition): bool => $definition[4] === $key);
            $note = 'InventoryDemoSeeder mapping PO '.$key;
            $order = VendorPurchaseOrder::query()->where('restaurant_id', $restaurant->id)
                ->where('vendor_id', $vendor->id)->where('notes', $note)->first();
            if (! $order) {
                $submittedItems = [];
                foreach ($vendorItems as $definition) {
                    [$name, , , , , $cost] = $definition;
                    $item = $items[$name];
                    $purchaseUnit = InventoryUnit::query()->where('restaurant_id', $restaurant->id)
                        ->findOrFail($item->purchase_unit_id);
                    $factor = $this->purchaseUnitBaseFactor($purchaseUnit);
                    $submittedItems[] = [
                        'item_name' => $name,
                        'quantity' => '1.000',
                        'unit' => $purchaseUnit->abbreviation,
                        'unit_price' => number_format((float) $cost * $factor, 2, '.', ''),
                        'discount_amount' => '0.00',
                        'tax_rate' => '5.00',
                    ];
                }
                $order = $procurement->createOrder($owner, $vendor, [
                    'order_date' => today()->subDays(3)->toDateString(),
                    'expected_delivery_date' => today()->addDays(4)->toDateString(),
                    'items' => $submittedItems,
                    'notes' => $note,
                ]);
            }

            $orderItems = $order->items()->get()->keyBy('item_name');
            foreach ($vendorItems as $definition) {
                $name = $definition[0];
                $item = $items[$name];
                $orderItem = $orderItems->get($name);
                if (! $orderItem) {
                    throw new RuntimeException("Inventory demo vendor mapping order for {$vendor->name} is missing its {$name} purchase line.");
                }
                $purchaseUnit = $units[$orderItem->unit] ?? InventoryUnit::query()
                    ->where('restaurant_id', $restaurant->id)->where('abbreviation', $orderItem->unit)->firstOrFail();
                $baseUnit = $units[$this->baseUnitAbbreviation($definition[2])];
                DB::table('inventory_vendor_item_mappings')->updateOrInsert(
                    [
                        'restaurant_id' => $restaurant->id,
                        'purchase_order_item_id' => $orderItem->id,
                    ],
                    [
                        'inventory_item_id' => $item->id,
                        'location_id' => $locations[$definition[3]]->id,
                        'unit_id' => $purchaseUnit->id,
                        'base_quantity_per_unit' => number_format(
                            $this->purchaseUnitBaseFactor($purchaseUnit),
                            9,
                            '.',
                            '',
                        ),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                if ($purchaseUnit->dimension !== $baseUnit->dimension) {
                    throw new RuntimeException("Inventory demo mapping unit {$purchaseUnit->abbreviation} is incompatible with {$name} base unit.");
                }
            }
        }
    }

    private function baseUnitAbbreviation(string $unitType): string
    {
        return match ($unitType) {
            'mass' => 'kg',
            'volume' => 'L',
            'count' => 'piece',
            'package' => 'packet',
        };
    }

    private function purchaseUnitBaseFactor(InventoryUnit $purchaseUnit): float
    {
        return match ($purchaseUnit->abbreviation) {
            'g', 'ml' => 0.001,
            'dozen' => 12.0,
            default => 1.0,
        };
    }
}
