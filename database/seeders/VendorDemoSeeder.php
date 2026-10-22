<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPurchaseOrder;
use App\Services\VendorProcurementService;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VendorDemoSeeder extends Seeder
{
    private const ACTIVE_VENDOR_COUNT = 8;

    private const ORDER_STATUSES = [
        'received',
        'draft',
        'received',
        'partially_received',
        'received',
        'partially_received',
        'received',
        'partially_received',
        'received',
        'confirmed',
        'cancelled',
        'draft',
        'cancelled',
        'confirmed',
        'cancelled',
    ];

    private const RECEIPT_ORDER_INDICES = [0, 2, 3, 4, 5, 6, 7, 8];

    private const REGIONS = [
        ['state' => 'Karnataka', 'state_code' => '29', 'city' => 'Bengaluru', 'pin_prefix' => '5'],
        ['state' => 'Maharashtra', 'state_code' => '27', 'city' => 'Pune', 'pin_prefix' => '4'],
        ['state' => 'Delhi', 'state_code' => '07', 'city' => 'New Delhi', 'pin_prefix' => '1'],
        ['state' => 'Tamil Nadu', 'state_code' => '33', 'city' => 'Chennai', 'pin_prefix' => '6'],
        ['state' => 'Telangana', 'state_code' => '36', 'city' => 'Hyderabad', 'pin_prefix' => '5'],
        ['state' => 'Uttar Pradesh', 'state_code' => '09', 'city' => 'Lucknow', 'pin_prefix' => '2'],
        ['state' => 'Gujarat', 'state_code' => '24', 'city' => 'Ahmedabad', 'pin_prefix' => '3'],
        ['state' => 'Haryana', 'state_code' => '06', 'city' => 'Gurugram', 'pin_prefix' => '1'],
        ['state' => 'Kerala', 'state_code' => '32', 'city' => 'Kochi', 'pin_prefix' => '6'],
        ['state' => 'West Bengal', 'state_code' => '19', 'city' => 'Kolkata', 'pin_prefix' => '7'],
    ];

    private const VENDOR_CATEGORIES = [
        'Produce',
        'Grains & Pulses',
        'Dairy',
        'Poultry & Meat',
        'Spices',
        'Cooking Oil',
        'Bakery',
        'Beverages',
        'Packaging',
        'Cleaning Supplies',
    ];

    private const CATEGORY_ITEMS = [
        'Produce' => [
            ['item_name' => 'Tomatoes', 'quantity' => '40.000', 'unit' => 'kg', 'unit_price' => '32.00'],
            ['item_name' => 'Onions', 'quantity' => '30.000', 'unit' => 'kg', 'unit_price' => '28.00'],
            ['item_name' => 'Potatoes', 'quantity' => '25.000', 'unit' => 'kg', 'unit_price' => '26.00'],
        ],
        'Grains & Pulses' => [
            ['item_name' => 'Basmati Rice', 'quantity' => '50.000', 'unit' => 'kg', 'unit_price' => '92.00'],
            ['item_name' => 'Toor Dal', 'quantity' => '20.000', 'unit' => 'kg', 'unit_price' => '128.00'],
            ['item_name' => 'Wheat Flour', 'quantity' => '25.000', 'unit' => 'kg', 'unit_price' => '42.00'],
        ],
        'Dairy' => [
            ['item_name' => 'Full Cream Milk', 'quantity' => '40.000', 'unit' => 'litre', 'unit_price' => '62.00'],
            ['item_name' => 'Paneer', 'quantity' => '12.000', 'unit' => 'kg', 'unit_price' => '340.00'],
            ['item_name' => 'Butter', 'quantity' => '8.000', 'unit' => 'kg', 'unit_price' => '480.00'],
        ],
        'Poultry & Meat' => [
            ['item_name' => 'Chicken', 'quantity' => '35.000', 'unit' => 'kg', 'unit_price' => '245.00'],
            ['item_name' => 'Eggs', 'quantity' => '20.000', 'unit' => 'dozen', 'unit_price' => '78.00'],
            ['item_name' => 'Mutton', 'quantity' => '10.000', 'unit' => 'kg', 'unit_price' => '760.00'],
        ],
        'Spices' => [
            ['item_name' => 'Turmeric Powder', 'quantity' => '5.000', 'unit' => 'kg', 'unit_price' => '220.00'],
            ['item_name' => 'Red Chilli Powder', 'quantity' => '5.000', 'unit' => 'kg', 'unit_price' => '310.00'],
            ['item_name' => 'Cumin Seeds', 'quantity' => '3.000', 'unit' => 'kg', 'unit_price' => '420.00'],
        ],
        'Cooking Oil' => [
            ['item_name' => 'Sunflower Oil', 'quantity' => '30.000', 'unit' => 'litre', 'unit_price' => '148.00'],
            ['item_name' => 'Groundnut Oil', 'quantity' => '15.000', 'unit' => 'litre', 'unit_price' => '196.00'],
            ['item_name' => 'Ghee', 'quantity' => '8.000', 'unit' => 'kg', 'unit_price' => '560.00'],
        ],
        'Bakery' => [
            ['item_name' => 'Burger Buns', 'quantity' => '120.000', 'unit' => 'piece', 'unit_price' => '8.00'],
            ['item_name' => 'Brown Bread', 'quantity' => '30.000', 'unit' => 'loaf', 'unit_price' => '48.00'],
            ['item_name' => 'Pizza Base', 'quantity' => '50.000', 'unit' => 'piece', 'unit_price' => '22.00'],
        ],
        'Beverages' => [
            ['item_name' => 'Mineral Water', 'quantity' => '100.000', 'unit' => 'bottle', 'unit_price' => '12.00'],
            ['item_name' => 'Mango Pulp', 'quantity' => '20.000', 'unit' => 'litre', 'unit_price' => '115.00'],
            ['item_name' => 'Tea Leaves', 'quantity' => '5.000', 'unit' => 'kg', 'unit_price' => '390.00'],
        ],
        'Packaging' => [
            ['item_name' => 'Kraft Food Boxes', 'quantity' => '500.000', 'unit' => 'piece', 'unit_price' => '4.50'],
            ['item_name' => 'Paper Cups', 'quantity' => '500.000', 'unit' => 'piece', 'unit_price' => '1.80'],
            ['item_name' => 'Carry Bags', 'quantity' => '300.000', 'unit' => 'piece', 'unit_price' => '2.20'],
        ],
        'Cleaning Supplies' => [
            ['item_name' => 'Dishwashing Liquid', 'quantity' => '20.000', 'unit' => 'litre', 'unit_price' => '92.00'],
            ['item_name' => 'Floor Cleaner', 'quantity' => '20.000', 'unit' => 'litre', 'unit_price' => '78.00'],
            ['item_name' => 'Food-safe Gloves', 'quantity' => '200.000', 'unit' => 'pair', 'unit_price' => '3.50'],
        ],
    ];

    public function run(): void
    {
        $restaurant = Restaurant::query()->where('name', 'Test resturent')->sole();
        $faker = Factory::create('en_IN');
        $faker->seed(20261022);

        DB::transaction(function () use ($restaurant, $faker): void {
            $owner = $restaurant->owners()->orderBy('id')->first();
            $vendors = $this->seedVendors($restaurant, $faker);
            $this->seedPurchaseOrders($restaurant, $vendors, $owner, app(VendorProcurementService::class));
        });
    }

    /**
     * @return array<int, Vendor>
     */
    private function seedVendors(Restaurant $restaurant, \Faker\Generator $faker): array
    {
        $vendors = [];
        $banks = [
            ['name' => 'State Bank of India', 'ifsc' => 'SBIN'],
            ['name' => 'HDFC Bank', 'ifsc' => 'HDFC'],
            ['name' => 'ICICI Bank', 'ifsc' => 'ICIC'],
            ['name' => 'Axis Bank', 'ifsc' => 'UTIB'],
            ['name' => 'Punjab National Bank', 'ifsc' => 'PUNB'],
        ];

        foreach (self::VENDOR_CATEGORIES as $index => $category) {
            $region = self::REGIONS[$index];
            $bank = $banks[$index % count($banks)];
            $businessName = $faker->company().' '.$faker->randomElement(['Foods', 'Suppliers', 'Distributors']);
            $pan = $faker->regexify('[A-Z]{5}[0-9]{4}[A-Z]');
            $hasGstin = $index < 8;
            $gstin = $hasGstin
                ? $region['state_code'].$pan.'1Z'.$faker->randomDigit()
                : null;

            $attributes = [
                'name' => $businessName,
                'contact_person' => $faker->name(),
                'mobile' => '+919'.$faker->numerify('#########'),
                'alternate_mobile' => '+918'.$faker->numerify('#########'),
                'email' => $faker->unique()->safeEmail(),
                'category' => $category,
                'status' => $index < self::ACTIVE_VENDOR_COUNT ? 'active' : 'inactive',
                'business_name' => $businessName,
                'gstin' => $gstin,
                'pan_number' => $pan,
                'registration_number' => $hasGstin ? 'REG-'.$pan : null,
                'payment_terms_days' => [7, 15, 30, 45, 60][$index % 5],
                'credit_limit' => number_format(50000 + ($index * 12500), 2, '.', ''),
                'address_line_1' => $faker->streetAddress(),
                'address_line_2' => $faker->numerify('Near Market Road ##'),
                'city' => $region['city'],
                'state' => $region['state'],
                'postal_code' => $faker->numerify($region['pin_prefix'].'#####'),
                'country' => 'India',
                'account_holder_name' => $businessName,
                'bank_name' => $bank['name'],
                'account_number' => $faker->numerify('############'),
                'ifsc_code' => $bank['ifsc'].$faker->numerify('0######'),
                'bank_branch' => $region['city'].' Main Branch',
                'upi_id' => 'payments'.($index + 1).'@upi',
                'notes' => $faker->sentence(),
            ];

            $vendor = Vendor::withTrashed()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'vendor_code' => sprintf('DEMO-VND-%04d', $index + 1),
                ],
                $attributes,
            );

            if ($vendor->trashed()) {
                $vendor->restore();
            }

            $vendors[] = $vendor;
        }

        return $vendors;
    }

    /**
     * @param  array<int, Vendor>  $vendors
     */
    private function seedPurchaseOrders(
        Restaurant $restaurant,
        array $vendors,
        ?User $owner,
        VendorProcurementService $procurement,
    ): void {
        foreach (self::ORDER_STATUSES as $index => $status) {
            $vendor = $vendors[$index % self::ACTIVE_VENDOR_COUNT];
            $orderDate = today()->toImmutable()->subDays(30 - $index);
            $submittedItems = array_map(
                static fn (array $item): array => [
                    ...$item,
                    'discount_amount' => '0.00',
                    'tax_rate' => '5.00',
                ],
                self::CATEGORY_ITEMS[$vendor->category],
            );
            [$items, $totals] = $procurement->calculateItems($submittedItems);
            $confirmedAt = in_array($status, ['confirmed', 'partially_received', 'received'], true)
                ? $orderDate->startOfDay()
                : null;

            $order = $restaurant->vendorPurchaseOrders()->updateOrCreate(
                ['po_number' => sprintf('DEMO-PO-%04d', $index + 1)],
                [
                    'vendor_id' => $vendor->id,
                    'order_date' => $orderDate,
                    'expected_delivery_date' => $orderDate->addDays(7),
                    'status' => $status,
                    ...$totals,
                    'notes' => 'Demo purchase order for vendor module review.',
                    'created_by' => $owner?->id,
                    'confirmed_at' => $confirmedAt,
                    'cancelled_at' => $status === 'cancelled' ? $orderDate->addDay()->startOfDay() : null,
                ],
            );

            foreach ($items as $item) {
                $order->items()->updateOrCreate(
                    ['item_name' => $item['item_name']],
                    ['restaurant_id' => $restaurant->id, ...$item],
                );
            }

            if (in_array($index, self::RECEIPT_ORDER_INDICES, true)) {
                $this->seedGoodsReceipt($restaurant, $order, $owner, $index, $status);
            }
        }
    }

    private function seedGoodsReceipt(
        Restaurant $restaurant,
        VendorPurchaseOrder $order,
        ?User $owner,
        int $orderIndex,
        string $status,
    ): void {
        $receiptSequence = array_search($orderIndex, self::RECEIPT_ORDER_INDICES, true) + 1;
        $receipt = $restaurant->vendorGoodsReceipts()->updateOrCreate(
            ['idempotency_key' => sprintf('vendor-demo-grn-%04d', $receiptSequence)],
            [
                'purchase_order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'grn_number' => sprintf('DEMO-GRN-%04d', $receiptSequence),
                'received_date' => $order->order_date->addDays(4),
                'received_by' => $owner?->id,
                'notes' => 'Demo delivery received against '.$order->po_number.'.',
            ],
        );
        $isPartial = $status === 'partially_received';

        foreach ($order->items()->get() as $orderItem) {
            $receivedQuantity = (float) $orderItem->quantity * ($isPartial ? 0.5 : 1);
            $quantity = number_format($receivedQuantity, 3, '.', '');

            $receipt->items()->updateOrCreate(
                ['purchase_order_item_id' => $orderItem->id],
                [
                    'restaurant_id' => $restaurant->id,
                    'item_name' => $orderItem->item_name,
                    'unit' => $orderItem->unit,
                    'ordered_quantity' => $orderItem->quantity,
                    'previously_received_quantity' => '0.000',
                    'received_quantity' => $quantity,
                    'rejected_quantity' => '0.000',
                    'accepted_quantity' => $quantity,
                ],
            );
        }
    }
}
