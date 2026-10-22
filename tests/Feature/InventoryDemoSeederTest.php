<?php

namespace Tests\Feature;

use App\Models\MenuRecipe;
use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\InventoryStockService;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_demo_seeder_is_local_only_idempotent_and_populates_inventory_pages(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Test resturent',
            'slug' => 'inventory-demo-test',
            'email' => 'inventory-demo-test@example.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Inventory Demo Owner',
            'email' => 'inventory-demo-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Inventory Demo Test Plan',
            'code' => 'INVENTORY-DEMO-TEST',
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
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
        $menuCategory = RestaurantMenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Demo Mains',
            'slug' => 'demo-mains',
            'is_active' => true,
        ]);
        foreach (['Chicken Biryani', 'Mutton Biryani', 'Paneer Butter Masala'] as $index => $name) {
            RestaurantMenuItem::create([
                'restaurant_id' => $restaurant->id,
                'category_id' => $menuCategory->id,
                'name' => $name,
                'price' => (string) (240 + ($index * 50)),
                'food_type' => $name === 'Paneer Butter Masala' ? 'veg' : 'non_veg',
                'is_available' => true,
                'is_active' => true,
            ]);
        }

        $this->actingAs($owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword()),
        ]);

        $this->app->detectEnvironment(static fn (): string => 'local');
        app(InventoryDemoSeeder::class)->run();
        $movementCount = DB::table('inventory_movements')->where('restaurant_id', $restaurant->id)->count();
        app(InventoryDemoSeeder::class)->run();
        $this->app->detectEnvironment(static fn (): string => 'testing');

        $this->assertSame(12, DB::table('inventory_categories')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(7, DB::table('inventory_units')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(5, DB::table('inventory_locations')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(40, DB::table('inventory_items')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame($movementCount, DB::table('inventory_movements')->where('restaurant_id', $restaurant->id)->count());
        $this->assertGreaterThan(0, DB::table('inventory_stock_transfers')->where('restaurant_id', $restaurant->id)->count());
        $this->assertGreaterThan(0, DB::table('inventory_adjustments')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(1, DB::table('inventory_stock_counts')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(1, DB::table('inventory_wastage_records')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(3, MenuRecipe::query()->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(40, DB::table('inventory_vendor_item_mappings')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(0, DB::table('inventory_items as item')
            ->join('inventory_units as base_unit', 'base_unit.id', '=', 'item.base_unit_id')
            ->leftJoin('inventory_units as purchase_unit', 'purchase_unit.id', '=', 'item.purchase_unit_id')
            ->leftJoin('inventory_units as consumption_unit', 'consumption_unit.id', '=', 'item.consumption_unit_id')
            ->where('item.restaurant_id', $restaurant->id)
            ->where(function ($query): void {
                $query->whereColumn('base_unit.dimension', '!=', 'purchase_unit.dimension')
                    ->orWhereColumn('base_unit.dimension', '!=', 'consumption_unit.dimension');
            })->count());

        $prawns = DB::table('inventory_items')->where('restaurant_id', $restaurant->id)->where('name', 'Prawns')->first();
        $prawnsStock = (float) DB::table('inventory_stock_balances')->where('restaurant_id', $restaurant->id)
            ->where('inventory_item_id', $prawns->id)->sum('quantity');
        $this->assertLessThanOrEqual((float) $prawns->reorder_level, $prawnsStock);
        $this->assertGreaterThan(0, DB::table('inventory_item_batches')->where('restaurant_id', $restaurant->id)
            ->where('available_quantity', '>', 0)->whereDate('expires_at', '<=', today()->addDays(30))->count());
        $this->assertEquals(
            1000.0,
            (float)
            DB::table('inventory_unit_conversions as conversion')
                ->join('inventory_units as from_unit', 'from_unit.id', '=', 'conversion.from_unit_id')
                ->join('inventory_units as to_unit', 'to_unit.id', '=', 'conversion.to_unit_id')
                ->where('conversion.restaurant_id', $restaurant->id)
                ->where('from_unit.abbreviation', 'kg')->where('to_unit.abbreviation', 'g')
                ->value('conversion.factor'),
        );
        $this->assertDatabaseHas('inventory_items', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Basmati Rice',
            'preferred_vendor_id' => DB::table('vendors')->where('restaurant_id', $restaurant->id)
                ->where('vendor_code', 'INV-DEMO-VND-PROVISIONS')->value('id'),
        ]);
        $this->assertEquals(12.0, (float) DB::table('inventory_vendor_item_mappings as mapping')
            ->join('vendor_purchase_order_items as line', 'line.id', '=', 'mapping.purchase_order_item_id')
            ->where('mapping.restaurant_id', $restaurant->id)->where('line.item_name', 'Eggs')
            ->value('mapping.base_quantity_per_unit'));

        $this->get(route('restaurant.inventory.overview'))
            ->assertOk()->assertSee('Basmati Rice')->assertSee('Low stock alerts')
            ->assertDontSee('Stock levels look good')->assertSee('Expiring batches');
        $this->get(route('restaurant.inventory.items.index'))->assertOk()->assertSee('Basmati Rice');
        $this->get(route('restaurant.inventory.items.create'))
            ->assertOk()->assertSee('Rice')->assertSee('kg')->assertSee('Dry Store')
            ->assertSee('Inventory Demo Grocery & Spice Supplier');
        $this->get(route('restaurant.inventory.categories.index'))->assertOk()->assertSee('Frozen Foods');
        $this->get(route('restaurant.inventory.units.index'))->assertOk()->assertSee('1000');
        $this->get(route('restaurant.inventory.locations.index'))->assertOk()->assertSee('Cold Storage');
        $this->get(route('restaurant.inventory.counts.index'))->assertOk()->assertSee('CNT');
        $countId = DB::table('inventory_stock_counts')->where('restaurant_id', $restaurant->id)->value('id');
        $this->get(route('restaurant.inventory.counts.show', $countId))->assertOk()->assertSee('Potato');
        $this->get(route('restaurant.inventory.wastage.index'))->assertOk()->assertSee('WST');
        $this->get(route('restaurant.inventory.recipes.index'))->assertOk()->assertSee('Chicken Biryani');
        $this->get(route('restaurant.inventory.vendor-mappings.index'))->assertOk()->assertSee('Basmati Rice');
        $this->get(route('restaurant.inventory.ledger'))->assertOk()->assertSee('opening');
        $this->get(route('restaurant.inventory.batches'))->assertOk()->assertSee('Chicken');
        $this->get(route('restaurant.inventory.reports'))->assertOk()->assertSee('Current stock');
        $this->get(route('restaurant.inventory.reports', ['report' => 'low']))->assertOk()->assertSee('Prawns');
        $this->get(route('restaurant.inventory.reports', ['report' => 'expiry']))->assertOk()->assertSee('Tomato');
        $this->get(route('restaurant.inventory.reports', ['report' => 'ingredient_costs']))
            ->assertOk()->assertSee('Chicken Biryani');
        $this->get(route('restaurant.inventory.settings'))->assertOk()->assertSee('Dry Store');
        foreach (['in', 'out', 'transfer', 'adjustment', 'wastage', 'opening'] as $kind) {
            $this->get(route('restaurant.inventory.stock.create', ['kind' => $kind]))->assertOk();
        }

        $this->assertSame(1, DB::table('inventory_stock_balances')->where('restaurant_id', $restaurant->id)
            ->where('inventory_item_id', $prawns->id)->where('location_id', $prawns->default_location_id)->count());
        $this->assertSame('1000.000000', app(InventoryStockService::class)->convertQuantity(
            (int) $restaurant->id,
            '1',
            (int) DB::table('inventory_units')->where('restaurant_id', $restaurant->id)->where('abbreviation', 'kg')->value('id'),
            (int) DB::table('inventory_units')->where('restaurant_id', $restaurant->id)->where('abbreviation', 'g')->value('id'),
        ));
    }

    public function test_inventory_demo_seeder_refuses_to_run_outside_local_environment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('restricted to APP_ENV=local');

        app(InventoryDemoSeeder::class)->run();
    }
}
