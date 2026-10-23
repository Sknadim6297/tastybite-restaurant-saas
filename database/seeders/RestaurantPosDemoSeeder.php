<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantFloor;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderItem;
use App\Models\RestaurantPosOrderStatusHistory;
use App\Models\RestaurantPosPayment;
use App\Models\RestaurantSection;
use App\Models\RestaurantTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RestaurantPosDemoSeeder extends Seeder
{
    private const RESTAURANT_ID = 2;

    private const RESTAURANT_NAME = 'Test resturent';

    private const RESTAURANT_SLUG = 'test-restaurant';

    private const ORDER_COUNT = 50;

    private const DEMO_KEY_PREFIX = 'demo-pos:2:';

    private const FLOOR_CODE = 'DEMO-POS';

    private const SECTION_CODE = 'DEMO-POS';

    private const IMAGE_ASSETS = [
        'biryani' => 'biryani.webp',
        'rice' => 'food-1639557.webp',
        'chicken' => 'food-1640777.webp',
        'mutton' => 'food-1640777.webp',
        'vegetarian' => 'food-70497.webp',
        'chinese' => 'food-262978.webp',
        'starters' => 'food-1437267.webp',
        'fast food' => 'food-958545.webp',
        'beverages' => 'food-376464.webp',
        'desserts' => 'food-616401.webp',
    ];

    private const ORDER_TYPES = [
        'dine_in', 'dine_in', 'takeaway', 'dine_in', 'delivery',
        'takeaway', 'dine_in', 'delivery', 'dine_in', 'takeaway',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('RestaurantPosDemoSeeder is restricted to APP_ENV=local.');
        }

        $this->assertSchemaReady();

        $restaurant = Restaurant::query()->find(self::RESTAURANT_ID);
        if (
            ! $restaurant
            || $restaurant->name !== self::RESTAURANT_NAME
            || $restaurant->slug !== self::RESTAURANT_SLUG
        ) {
            throw new RuntimeException('Restaurant ID 2 did not match the verified Test resturent identity; no demo data was written.');
        }

        $owner = $restaurant->owners()->orderBy('id')->first();
        if (! $owner) {
            throw new RuntimeException('The verified demo restaurant has no owner account to attribute POS orders to.');
        }

        $menuItems = $restaurant->menuItems()
            ->with('category')
            ->orderBy('id')
            ->get();
        $orderMenuItems = $menuItems->where('is_active', true)->where('is_available', true)->values();
        if ($orderMenuItems->isEmpty()) {
            throw new RuntimeException('The verified demo restaurant has no active, available menu items. Seed its demo menu first.');
        }

        $this->assertImageAssetsReady();

        $result = DB::transaction(function () use ($restaurant, $owner, $menuItems, $orderMenuItems): array {
            $restaurant = Restaurant::query()->whereKey(self::RESTAURANT_ID)->lockForUpdate()->firstOrFail();
            $floor = $this->ensureFloor($restaurant);
            $section = $this->ensureSection($restaurant, $floor);
            $tables = $this->ensureTables($restaurant, $floor, $section);
            $this->ensureMenuImages($menuItems);

            $faker = Factory::create('en_IN');
            $faker->seed(20261023);
            $createdOrders = 0;
            for ($index = 1; $index <= self::ORDER_COUNT; $index++) {
                $createdOrders += $this->seedOrder($index, $restaurant, $owner, $orderMenuItems, $tables, $faker);
            }

            return [
                'created_orders' => $createdOrders,
                'existing_orders' => self::ORDER_COUNT - $createdOrders,
                'tables' => $tables->count(),
                'menu_items' => $menuItems->count(),
            ];
        });

        $this->command?->info(sprintf(
            'Restaurant ID %d: %d POS demo orders created, %d already present; %d demo tables verified; %d menu items checked.',
            self::RESTAURANT_ID,
            $result['created_orders'],
            $result['existing_orders'],
            $result['tables'],
            $result['menu_items'],
        ));
    }

    private function assertSchemaReady(): void
    {
        $requiredColumns = [
            'restaurant_pos_orders' => [
                'restaurant_id', 'created_by', 'order_number', 'status', 'idempotency_key',
                'subtotal', 'discount', 'tax', 'total', 'completed_at', 'order_type',
                'table_number', 'guest_count', 'customer_name', 'customer_mobile',
                'delivery_address', 'payment_status', 'payment_method', 'fulfillment_status',
                'floor_id', 'section_id', 'table_id', 'original_table_id',
            ],
            'restaurant_pos_order_items' => [
                'restaurant_id', 'pos_order_id', 'menu_item_id', 'item_name', 'quantity', 'unit_price',
            ],
            'restaurant_pos_payments' => [
                'restaurant_id', 'pos_order_id', 'recorded_by', 'payment_reference',
                'idempotency_key', 'amount', 'method', 'status', 'recorded_at',
            ],
            'restaurant_pos_order_status_history' => [
                'restaurant_id', 'pos_order_id', 'actor_id', 'actor_type', 'from_status', 'to_status', 'changed_at',
            ],
            'restaurant_floors' => ['restaurant_id', 'name', 'code', 'display_order', 'is_active', 'archived_at'],
            'restaurant_sections' => ['restaurant_id', 'floor_id', 'name', 'code', 'is_active', 'archived_at'],
            'restaurant_tables' => [
                'restaurant_id', 'floor_id', 'section_id', 'table_number', 'capacity', 'status', 'is_active', 'archived_at',
            ],
            'restaurant_menu_items' => ['restaurant_id', 'category_id', 'name', 'price', 'image_path', 'is_active', 'is_available'],
        ];

        $missing = [];
        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('POS demo seeding requires the existing POS/table schema. Missing: '.implode(', ', $missing));
        }
    }

    private function assertImageAssetsReady(): void
    {
        foreach (array_unique(self::IMAGE_ASSETS) as $asset) {
            $path = database_path('seeders/assets/restaurant-pos-demo/'.$asset);
            if (! File::exists($path) || @getimagesize($path) === false) {
                throw new RuntimeException("POS demo image asset {$asset} is missing or invalid.");
            }
        }
    }

    private function ensureFloor(Restaurant $restaurant): RestaurantFloor
    {
        $floor = $restaurant->floors()->where('code', self::FLOOR_CODE)->first();
        if ($floor) {
            if ($floor->name !== 'Demo Dining Room') {
                throw new RuntimeException('The DEMO-POS floor code is already assigned to a different floor.');
            }

            return $floor;
        }

        return $restaurant->floors()->create([
            'name' => 'Demo Dining Room',
            'code' => self::FLOOR_CODE,
            'display_order' => 90,
            'description' => 'Isolated sample floor created by RestaurantPosDemoSeeder.',
            'is_active' => true,
        ]);
    }

    private function ensureSection(Restaurant $restaurant, RestaurantFloor $floor): RestaurantSection
    {
        $section = $restaurant->diningSections()->where('code', self::SECTION_CODE)->first();
        if ($section) {
            if ($section->floor_id !== $floor->id || $section->name !== 'Demo Dining Section') {
                throw new RuntimeException('The DEMO-POS section code is already assigned to a different section.');
            }

            return $section;
        }

        return $restaurant->diningSections()->create([
            'floor_id' => $floor->id,
            'name' => 'Demo Dining Section',
            'code' => self::SECTION_CODE,
            'description' => 'Isolated sample section created by RestaurantPosDemoSeeder.',
            'sort_order' => 90,
            'is_active' => true,
        ]);
    }

    /** @return Collection<int, RestaurantTable> */
    private function ensureTables(Restaurant $restaurant, RestaurantFloor $floor, RestaurantSection $section)
    {
        $tables = collect();
        for ($number = 1; $number <= 12; $number++) {
            $tableNumber = sprintf('DEMO-%02d', $number);
            $table = $restaurant->diningTables()->where('table_number', $tableNumber)->first();
            if ($table) {
                if ($table->floor_id !== $floor->id || $table->section_id !== $section->id) {
                    throw new RuntimeException("Table {$tableNumber} already exists outside the isolated demo dining section.");
                }
                if (! $table->is_active || $table->archived_at !== null) {
                    throw new RuntimeException("Demo table {$tableNumber} is inactive or archived and was not changed.");
                }
            } else {
                $table = $restaurant->diningTables()->create([
                    'floor_id' => $floor->id,
                    'section_id' => $section->id,
                    'table_number' => $tableNumber,
                    'name' => 'Demo Table '.$number,
                    'capacity' => [2, 2, 4, 4, 4, 4, 6, 6, 6, 2, 4, 6][$number - 1],
                    'shape' => $number % 3 === 0 ? 'round' : 'rectangle',
                    'type' => 'standard',
                    'sort_order' => $number,
                    'is_active' => true,
                    'status' => 'available',
                ]);
            }

            $tables->push($table);
        }

        return $tables;
    }

    private function ensureMenuImages($menuItems): void
    {
        $disk = Storage::disk('public');
        $restaurantPrefix = 'restaurant-menu/'.self::RESTAURANT_ID.'/';

        foreach ($menuItems as $item) {
            if ($this->hasValidOwnedImage($item->image_path, $restaurantPrefix)) {
                continue;
            }

            $category = mb_strtolower((string) $item->category?->name);
            $asset = self::IMAGE_ASSETS[$category] ?? ($item->food_type === 'veg' ? 'food-70497.webp' : 'food-1640777.webp');
            $imageName = 'pos-demo-'.Str::slug($category ?: $item->name).'.webp';
            $imagePath = $restaurantPrefix.$imageName;

            if (! $this->hasValidOwnedImage($imagePath, $restaurantPrefix)) {
                $sourcePath = database_path('seeders/assets/restaurant-pos-demo/'.$asset);
                $disk->put($imagePath, File::get($sourcePath));
            }

            $item->forceFill(['image_path' => $imagePath])->save();
        }
    }

    private function hasValidOwnedImage(?string $path, string $restaurantPrefix): bool
    {
        if (! is_string($path) || ! str_starts_with($path, $restaurantPrefix)) {
            return false;
        }

        if (! preg_match('/\Arestaurant-menu\/2\/[A-Za-z0-9._-]+\z/', $path)) {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return false;
        }

        return @getimagesizefromstring($disk->get($path)) !== false;
    }

    private function seedOrder(
        int $index,
        Restaurant $restaurant,
        $owner,
        $menuItems,
        $tables,
        Generator $faker,
    ): int {
        $sequence = sprintf('%04d', $index);
        $idempotencyKey = self::DEMO_KEY_PREFIX.$sequence;
        $orderNumber = 'DEMO-POS-'.$sequence;

        $existing = RestaurantPosOrder::query()
            ->where('restaurant_id', self::RESTAURANT_ID)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            if ($existing->order_number !== $orderNumber) {
                throw new RuntimeException("Demo order key {$idempotencyKey} is already tied to another order number.");
            }

            return 0;
        }

        if (RestaurantPosOrder::query()->where('restaurant_id', self::RESTAURANT_ID)->where('order_number', $orderNumber)->exists()) {
            throw new RuntimeException("Order number {$orderNumber} is already assigned to a non-demo order.");
        }

        $orderType = self::ORDER_TYPES[($index - 1) % count(self::ORDER_TYPES)];
        $table = $orderType === 'dine_in' ? $tables[($index - 1) % $tables->count()] : null;
        $guestCount = $table ? $faker->numberBetween(1, $table->capacity) : null;
        $pickedItems = $faker->randomElements(
            $menuItems->all(),
            $faker->numberBetween(1, min(4, $menuItems->count())),
        );
        $subtotal = BigDecimal::zero();
        $lineItems = [];

        foreach ($pickedItems as $menuItem) {
            $quantity = $faker->numberBetween(1, 3);
            $unitPrice = BigDecimal::of((string) $menuItem->price);
            $subtotal = $subtotal->plus($unitPrice->multipliedBy((string) $quantity));
            $lineItems[] = [$menuItem, $quantity];
        }

        $discountPercent = $faker->randomElement([0, 0, 5, 8, 10, 12, 15]);
        $discount = $subtotal->multipliedBy((string) $discountPercent)
            ->dividedBy('100', 2, RoundingMode::HALF_UP);
        $tax = $subtotal->minus($discount)->multipliedBy('0.05')->toScale(2, RoundingMode::HALF_UP);
        $total = $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HALF_UP);
        $isPaid = $index % 4 !== 0;
        $paymentMethod = $isPaid ? $faker->randomElement(['cash', 'card', 'upi']) : null;
        $orderedAt = CarbonImmutable::instance($faker->dateTimeBetween('-90 days', '-2 hours'));
        $completedAt = $orderedAt->addMinutes(55);
        $fulfillmentStatus = $this->fulfillmentStatus($orderType, $index);
        $customerName = $orderType === 'dine_in' ? null : $faker->name();

        $order = RestaurantPosOrder::query()->create([
            'restaurant_id' => $restaurant->id,
            'created_by' => $owner->id,
            'order_number' => $orderNumber,
            'status' => 'completed',
            'idempotency_key' => $idempotencyKey,
            'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'discount' => $discount->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'tax' => $tax->__toString(),
            'total' => $total->__toString(),
            'completed_at' => $completedAt,
            'order_type' => $orderType,
            'table_number' => $table?->table_number,
            'guest_count' => $guestCount,
            'customer_name' => $customerName,
            'customer_mobile' => $customerName ? $faker->numerify('9#########') : null,
            'delivery_address' => $orderType === 'delivery' ? $faker->address() : null,
            'notes' => $faker->optional(0.28)->randomElement([
                'Less spicy, please.', 'Pack sauces separately.', 'No cutlery needed.',
                'Call on arrival.', 'Serve the drinks first.',
            ]),
            'payment_status' => $isPaid ? 'paid' : 'unpaid',
            'payment_method' => $paymentMethod,
            'fulfillment_status' => $fulfillmentStatus,
            'floor_id' => $table?->floor_id,
            'section_id' => $table?->section_id,
            'table_id' => $table?->id,
            'original_table_id' => $table?->id,
            'created_at' => $orderedAt,
            'updated_at' => $completedAt,
        ]);

        foreach ($lineItems as [$menuItem, $quantity]) {
            RestaurantPosOrderItem::query()->create([
                'restaurant_id' => $restaurant->id,
                'pos_order_id' => $order->id,
                'menu_item_id' => $menuItem->id,
                'item_name' => $menuItem->name,
                'quantity' => $quantity,
                'unit_price' => $menuItem->price,
                'created_at' => $orderedAt,
                'updated_at' => $orderedAt,
            ]);
        }

        $this->seedStatusHistory($order, $orderType, $fulfillmentStatus, $owner->id, $orderedAt);

        if ($isPaid) {
            RestaurantPosPayment::query()->create([
                'restaurant_id' => $restaurant->id,
                'pos_order_id' => $order->id,
                'recorded_by' => $owner->id,
                'payment_reference' => 'DEMO-PAY-'.$sequence,
                'idempotency_key' => 'demo-pos-payment:2:'.$sequence,
                'amount' => $total->__toString(),
                'method' => $paymentMethod,
                'status' => 'recorded',
                'recorded_at' => $completedAt,
                'notes' => 'Demo payment record; no external payment was processed.',
                'created_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
        }

        return 1;
    }

    private function fulfillmentStatus(string $orderType, int $index): string
    {
        $statuses = match ($orderType) {
            'dine_in' => ['pending', 'confirmed', 'preparing', 'ready', 'served', 'completed'],
            'delivery' => ['pending', 'confirmed', 'preparing', 'ready', 'dispatched', 'completed'],
            default => ['pending', 'confirmed', 'preparing', 'ready', 'completed'],
        };

        return $statuses[($index * 7) % count($statuses)];
    }

    private function seedStatusHistory(
        RestaurantPosOrder $order,
        string $orderType,
        string $targetStatus,
        int $ownerId,
        CarbonImmutable $orderedAt,
    ): void {
        $statuses = match ($orderType) {
            'dine_in' => ['pending', 'confirmed', 'preparing', 'ready', 'served', 'completed'],
            'delivery' => ['pending', 'confirmed', 'preparing', 'ready', 'dispatched', 'completed'],
            default => ['pending', 'confirmed', 'preparing', 'ready', 'completed'],
        };
        $targetIndex = array_search($targetStatus, $statuses, true);
        $history = array_slice($statuses, 0, $targetIndex === false ? 1 : $targetIndex + 1);
        $previousStatus = null;

        foreach ($history as $step => $status) {
            $changedAt = $orderedAt->addMinutes($step * 8);
            RestaurantPosOrderStatusHistory::query()->create([
                'restaurant_id' => $order->restaurant_id,
                'pos_order_id' => $order->id,
                'actor_id' => $ownerId,
                'actor_type' => 'restaurant_user',
                'from_status' => $previousStatus,
                'to_status' => $status,
                'reason' => 'Demo POS order lifecycle.',
                'changed_at' => $changedAt,
                'created_at' => $changedAt,
                'updated_at' => $changedAt,
            ]);
            $previousStatus = $status;
        }
    }
}
