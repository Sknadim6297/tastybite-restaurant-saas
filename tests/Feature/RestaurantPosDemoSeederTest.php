<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosOrder;
use App\Models\User;
use Database\Seeders\RestaurantPosDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RestaurantPosDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_isolated_pos_demo_data_and_is_safe_to_rerun(): void
    {
        Storage::fake('public');
        $this->app->instance('env', 'local');
        [$restaurant, $owner, $menuItems] = $this->createDemoRestaurant();
        $realOrder = RestaurantPosOrder::query()->create([
            'restaurant_id' => $restaurant->id,
            'order_number' => 'REAL-ORDER-001',
            'status' => 'completed',
            'idempotency_key' => 'real-order-key-001',
            'subtotal' => '100.00',
            'discount' => '0.00',
            'tax' => '5.00',
            'total' => '105.00',
        ]);

        $seeder = new RestaurantPosDemoSeeder;
        $seeder->run();

        $this->assertDatabaseCount('restaurant_pos_orders', 51);
        $this->assertDatabaseCount('restaurant_tables', 12);
        $this->assertDatabaseCount('restaurant_sections', 1);
        $this->assertDatabaseCount('restaurant_floors', 1);
        $this->assertSame(50, RestaurantPosOrder::query()->where('idempotency_key', 'like', 'demo-pos:2:%')->count());
        $this->assertDatabaseHas('restaurant_pos_orders', [
            'id' => $realOrder->id,
            'order_number' => 'REAL-ORDER-001',
            'total' => '105.00',
            'idempotency_key' => 'real-order-key-001',
        ]);
        $this->assertSame(38, RestaurantPosOrder::query()->where('restaurant_id', 2)->where('payment_status', 'paid')->count());
        $this->assertSame(38, \App\Models\RestaurantPosPayment::query()->where('restaurant_id', 2)->count());
        $this->assertGreaterThan(0, \App\Models\RestaurantPosOrderStatusHistory::query()->where('restaurant_id', 2)->count());

        $types = RestaurantPosOrder::query()->where('idempotency_key', 'like', 'demo-pos:2:%')
            ->selectRaw('order_type, count(*) as total')->groupBy('order_type')->pluck('total', 'order_type');
        $this->assertSame(25, (int) $types['dine_in']);
        $this->assertSame(15, (int) $types['takeaway']);
        $this->assertSame(10, (int) $types['delivery']);

        $demoOrder = RestaurantPosOrder::query()->where('idempotency_key', 'demo-pos:2:0001')->firstOrFail();
        $this->assertGreaterThan(0, $demoOrder->items()->count());
        $this->assertEqualsWithDelta(
            (float) $demoOrder->subtotal - (float) $demoOrder->discount + (float) $demoOrder->tax,
            (float) $demoOrder->total,
            0.01,
        );
        $this->assertSame('Chicken Biryani', $menuItems[0]->fresh()->name);
        $this->assertSame('200.00', $menuItems[0]->fresh()->price);
        $this->assertStringStartsWith('restaurant-menu/2/', (string) $menuItems[0]->fresh()->image_path);
        Storage::disk('public')->assertExists($menuItems[0]->fresh()->image_path);

        $itemCountBeforeRerun = \App\Models\RestaurantPosOrderItem::query()->count();
        $seeder->run();

        $this->assertDatabaseCount('restaurant_pos_orders', 51);
        $this->assertDatabaseCount('restaurant_pos_order_items', $itemCountBeforeRerun);
        $this->assertDatabaseCount('restaurant_pos_payments', 38);
        $this->assertDatabaseCount('restaurant_tables', 12);
        $this->assertDatabaseHas('restaurant_pos_orders', ['id' => $realOrder->id, 'total' => '105.00']);
        $this->assertSame(3, $menuItems->count());
        $this->assertSame(2, $owner->fresh()->restaurant_id);
    }

    public function test_seeder_refuses_non_local_environment_and_unverified_restaurant_identity(): void
    {
        $this->app->instance('env', 'production');
        try {
            (new RestaurantPosDemoSeeder)->run();
            $this->fail('The demo seeder must refuse production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restricted to APP_ENV=local', $exception->getMessage());
        }
        $this->assertDatabaseCount('restaurant_pos_orders', 0);

        $this->app->instance('env', 'local');
        Restaurant::forceCreate(['id' => 2, 'name' => 'Different Restaurant', 'slug' => 'not-the-demo', 'status' => 'active']);
        try {
            (new RestaurantPosDemoSeeder)->run();
            $this->fail('The seeder must refuse an unverified restaurant identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not match the verified', $exception->getMessage());
        }
        $this->assertDatabaseCount('restaurant_pos_orders', 0);
        $this->assertDatabaseCount('restaurant_tables', 0);
    }

    /** @return array{Restaurant, User, \Illuminate\Support\Collection<int, RestaurantMenuItem>} */
    private function createDemoRestaurant(): array
    {
        $restaurant = Restaurant::forceCreate([
            'id' => 2,
            'name' => 'Test resturent',
            'slug' => 'test-restaurant',
            'status' => 'active',
        ]);
        $owner = User::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Demo Owner',
            'email' => 'demo-owner@example.test',
            'password' => 'StrongDemoPassword123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $category = RestaurantMenuCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Biryani',
            'slug' => 'biryani',
            'icon' => 'rice',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $menuItems = collect();
        foreach ([['Chicken Biryani', '200.00'], ['Paneer Biryani', '180.00'], ['Veg Biryani', '150.00']] as [$name, $price]) {
            $menuItems->push(RestaurantMenuItem::query()->create([
                'restaurant_id' => $restaurant->id,
                'category_id' => $category->id,
                'name' => $name,
                'price' => $price,
                'food_type' => str_contains($name, 'Paneer') || str_contains($name, 'Veg') ? 'veg' : 'non_veg',
                'is_active' => true,
                'is_available' => true,
                'sort_order' => $menuItems->count(),
            ]));
        }

        return [$restaurant, $owner, $menuItems];
    }
}