<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryUnit;
use App\Models\MenuRecipe;
use App\Models\MenuRecipeIngredient;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\InventoryStockService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class InventoryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $owner;

    private InventoryUnit $unit;

    private InventoryCategory $category;

    private InventoryLocation $location;

    private InventoryItem $item;

    private InventoryStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Restaurant::create([
            'name' => 'Inventory Test Restaurant',
            'slug' => 'inventory-test',
            'email' => 'inventory-test@example.test',
            'status' => 'active',
        ]);
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Inventory Owner',
            'email' => 'inventory-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->unit = InventoryUnit::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Kilogram',
            'abbreviation' => 'kg',
            'dimension' => 'mass',
            'status' => 'active',
        ]);
        $this->category = InventoryCategory::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Produce',
            'status' => 'active',
        ]);
        $this->location = InventoryLocation::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'status' => 'active',
        ]);
        $this->item = InventoryItem::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $this->category->id,
            'base_unit_id' => $this->unit->id,
            'item_code' => 'INV000001',
            'name' => 'Test Rice',
            'reorder_level' => '0.500000',
            'reorder_quantity' => '1.000000',
            'standard_cost' => '3.000000',
            'tax_rate' => '0.00',
            'status' => 'active',
            'track_batches' => false,
            'track_expiry' => false,
        ]);
        DB::table('inventory_settings')->insert([
            'restaurant_id' => $this->restaurant->id,
            'expiry_warning_days' => 30,
            'deduction_event' => 'sale_completed',
            'allow_negative_stock' => false,
            'valuation_method' => 'weighted_average',
            'consume_recipes' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->stock = app(InventoryStockService::class);
    }

    public function test_receipts_use_weighted_average_cost_and_retrying_a_key_does_not_duplicate_stock(): void
    {
        $first = $this->stock->receive($this->owner, $this->item, $this->location, '2.125', '4.500000', 'opening', 'opening-1');
        $retry = $this->stock->receive($this->owner, $this->item, $this->location, '2.125', '4.500000', 'opening', 'opening-1');
        $this->assertSame($first->id, $retry->id);

        $this->stock->receive($this->owner, $this->item, $this->location, '1.000000', '6.000000', 'manual_receipt', 'receipt-2');
        $this->stock->issue($this->owner, $this->item, $this->location, '1.125000', 'manual_issue', 'issue-1');

        $balance = DB::table('inventory_stock_balances')->where('inventory_item_id', $this->item->id)->first();
        $this->assertSame(0, BigDecimal::of((string) $balance->quantity)->compareTo('2'));
        $this->assertSame(0, BigDecimal::of((string) $balance->average_unit_cost)->compareTo('4.98'));
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_unit_conversions_support_forward_and_inverse_rates_without_cross_dimension_conversion(): void
    {
        $gram = InventoryUnit::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Gram',
            'abbreviation' => 'g',
            'dimension' => 'mass',
            'status' => 'active',
        ]);
        DB::table('inventory_unit_conversions')->insert([
            'restaurant_id' => $this->restaurant->id,
            'from_unit_id' => $this->unit->id,
            'to_unit_id' => $gram->id,
            'factor' => '1000.000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('1500.000000', $this->stock->convertQuantity((int) $this->restaurant->id, '1.5', $this->unit->id, $gram->id));
        $this->assertSame('1.500000', $this->stock->convertQuantity((int) $this->restaurant->id, '1500', $gram->id, $this->unit->id));

        $liter = InventoryUnit::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Liter',
            'abbreviation' => 'L',
            'dimension' => 'volume',
            'status' => 'active',
        ]);
        try {
            $this->stock->convertQuantity((int) $this->restaurant->id, '1', $this->unit->id, $liter->id);
            $this->fail('Different unit dimensions must not be converted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit_id', $exception->errors());
        }
    }

    public function test_tracked_issues_consume_unexpired_batches_in_fefo_order(): void
    {
        $this->item->update(['track_expiry' => true, 'track_batches' => true]);
        $firstBatch = $this->createBatch('FIRST', today()->addDay()->toDateString(), '1.000000');
        $secondBatch = $this->createBatch('SECOND', today()->addMonth()->toDateString(), '2.000000');
        $this->stock->receive($this->owner, $this->item, $this->location, '1.000000', '2.000000', 'purchase_receipt', 'batch-in-1', ['batch_id' => $firstBatch]);
        $this->stock->receive($this->owner, $this->item, $this->location, '2.000000', '3.000000', 'purchase_receipt', 'batch-in-2', ['batch_id' => $secondBatch]);

        $movements = $this->stock->issue($this->owner, $this->item, $this->location, '1.500000', 'consumption', 'fefo-issue');

        $this->assertCount(2, $movements);
        $this->assertSame(0, BigDecimal::of((string) DB::table('inventory_item_batches')->where('id', $firstBatch)->value('available_quantity'))->compareTo('0'));
        $this->assertSame(0, BigDecimal::of((string) DB::table('inventory_item_batches')->where('id', $secondBatch)->value('available_quantity'))->compareTo('1.5'));
    }

    public function test_positive_adjustments_for_tracked_items_require_and_update_lot_details(): void
    {
        $this->item->update(['track_expiry' => true, 'track_batches' => true]);

        $this->stock->adjust(
            $this->owner,
            $this->item,
            $this->location,
            '2.000000',
            'physical_count',
            'tracked-adjustment-1',
            now()->toDateTimeString(),
            null,
            'ADJ',
            ['batch_number' => 'COUNTED-LOT', 'expires_at' => today()->addMonth()->toDateString()],
        );

        $batch = DB::table('inventory_item_batches')->where('inventory_item_id', $this->item->id)->first();
        $this->assertNotNull($batch);
        $this->assertSame(0, BigDecimal::of((string) $batch->received_quantity)->compareTo('2'));
        $this->assertSame(0, BigDecimal::of((string) $batch->available_quantity)->compareTo('2'));

        $this->expectException(ValidationException::class);
        $this->stock->adjust(
            $this->owner,
            $this->item,
            $this->location,
            '3.000000',
            'physical_count',
            'tracked-adjustment-2',
        );
    }

    public function test_inventory_dashboard_and_management_pages_render_for_an_active_restaurant(): void
    {
        $product = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Rice Bowl',
            'price' => '10.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $recipe = MenuRecipe::create([
            'restaurant_id' => $this->restaurant->id,
            'menu_item_id' => $product->id,
            'version' => 1,
            'yield_quantity' => '1.000000',
            'is_active' => true,
        ]);
        MenuRecipeIngredient::create([
            'restaurant_id' => $this->restaurant->id,
            'menu_recipe_id' => $recipe->id,
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'quantity' => '0.250000',
            'base_quantity' => '0.250000',
        ]);
        $this->stock->receive($this->owner, $this->item, $this->location, '2.000000', '5.000000', 'opening', 'recipe-cost-opening');

        $plan = SubscriptionPlan::create([
            'name' => 'Inventory Feature Test',
            'code' => 'INVENTORY-FEATURE-TEST',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $this->restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);

        $this->get(route('restaurant.inventory.overview'))
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Inventory Items', 'Stock Management', 'Stock Control', 'Recipes & Mapping', 'Reports & Settings'])
            ->assertDontSee('sidebar-nested-group', false);
        $this->get(route('restaurant.inventory.categories.index'))
            ->assertOk()
            ->assertSee('id="inventory-tab-categories"', false)
            ->assertSee('aria-selected="true"', false);
        $this->get(route('restaurant.inventory.reports', ['report' => 'low']))
            ->assertOk()
            ->assertSee('id="inventory-tab-reports"', false)
            ->assertSee('value="low" selected', false);

        foreach ([
            'restaurant.inventory.overview' => [],
            'restaurant.inventory.items.index' => [],
            'restaurant.inventory.items.show' => [$this->item],
            'restaurant.inventory.categories.index' => [],
            'restaurant.inventory.units.index' => [],
            'restaurant.inventory.locations.index' => [],
            'restaurant.inventory.counts.index' => [],
            'restaurant.inventory.wastage.index' => [],
            'restaurant.inventory.batches' => [],
            'restaurant.inventory.ledger' => [],
            'restaurant.inventory.reports' => [],
            'restaurant.inventory.settings' => [],
            'restaurant.inventory.recipes.index' => [],
            'restaurant.inventory.vendor-mappings.index' => [],
        ] as $routeName => $parameters) {
            $response = $this->get(route($routeName, $parameters))->assertOk();
            $activeTab = match (true) {
                str_starts_with($routeName, 'restaurant.inventory.items.') => 'items',
                str_starts_with($routeName, 'restaurant.inventory.categories.') => 'categories',
                str_starts_with($routeName, 'restaurant.inventory.units.') => 'units',
                str_starts_with($routeName, 'restaurant.inventory.locations.') => 'locations',
                str_starts_with($routeName, 'restaurant.inventory.counts.') => 'counts',
                str_starts_with($routeName, 'restaurant.inventory.wastage.') => 'wastage',
                $routeName === 'restaurant.inventory.batches' => 'expiry',
                $routeName === 'restaurant.inventory.ledger' => 'ledger',
                $routeName === 'restaurant.inventory.reports' => 'reports',
                $routeName === 'restaurant.inventory.settings' => 'settings',
                str_starts_with($routeName, 'restaurant.inventory.recipes.') => 'recipes',
                str_starts_with($routeName, 'restaurant.inventory.vendor-mappings.') => 'mapping',
                default => null,
            };
            if ($activeTab !== null) {
                $selectedTab = preg_match(
                    '/<a\s+id="inventory-tab-'.preg_quote($activeTab, '/').'"[^>]*aria-selected="true"/',
                    $response->getContent(),
                );
                $this->assertSame(1, $selectedTab, "Expected {$routeName} to select the {$activeTab} workspace tab.");
            }
        }

        $this->get(route('restaurant.inventory.reports', ['report' => 'ingredient_costs']))
            ->assertOk()->assertSee('Recipe ingredient costs')->assertSee('₹1.25');
        $this->get(route('restaurant.inventory.stock.create', ['kind' => 'in']))
            ->assertOk()->assertSee('RECEIPT SUMMARY')->assertSee('data-stock-item', false);
        $this->get(route('restaurant.inventory.ledger', ['movement_type' => 'opening']))
            ->assertOk()->assertSee('Print ledger');
        $this->get(route('restaurant.inventory.items.export'))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_insufficient_stock_and_cross_restaurant_movements_are_rejected(): void
    {
        try {
            $this->stock->issue($this->owner, $this->item, $this->location, '1.000000', 'manual_issue', 'not-enough-stock');
            $this->fail('Issuing more than available stock must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertDatabaseCount('inventory_movements', 0);

        $foreignRestaurant = Restaurant::create([
            'name' => 'Foreign Restaurant',
            'slug' => 'foreign-inventory-test',
            'email' => 'foreign-inventory@example.test',
            'status' => 'active',
        ]);
        $foreignUnit = InventoryUnit::create([
            'restaurant_id' => $foreignRestaurant->id,
            'name' => 'Foreign Unit',
            'abbreviation' => 'fkg',
            'dimension' => 'mass',
            'status' => 'active',
        ]);
        $foreignCategory = InventoryCategory::create([
            'restaurant_id' => $foreignRestaurant->id,
            'name' => 'Foreign Category',
            'status' => 'active',
        ]);
        $foreignLocation = InventoryLocation::create([
            'restaurant_id' => $foreignRestaurant->id,
            'name' => 'Foreign Store',
            'code' => 'FOREIGN',
            'status' => 'active',
        ]);
        $foreignItem = InventoryItem::create([
            'restaurant_id' => $foreignRestaurant->id,
            'category_id' => $foreignCategory->id,
            'base_unit_id' => $foreignUnit->id,
            'item_code' => 'INV000002',
            'name' => 'Foreign Rice',
            'reorder_level' => '0',
            'reorder_quantity' => '0',
            'standard_cost' => '1.000000',
            'tax_rate' => '0.00',
            'status' => 'active',
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->stock->receive($this->owner, $foreignItem, $foreignLocation, '1', '1', 'opening', 'foreign-key');
    }

    private function createBatch(string $number, string $expiresAt, string $quantity): int
    {
        return DB::table('inventory_item_batches')->insertGetId([
            'restaurant_id' => $this->restaurant->id,
            'inventory_item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'batch_number' => $number,
            'expires_at' => $expiresAt,
            'received_quantity' => $quantity,
            'available_quantity' => '0.000000',
            'unit_cost' => '0.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
