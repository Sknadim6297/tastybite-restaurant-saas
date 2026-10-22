<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryUnit;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPurchaseOrder;
use App\Models\VendorPurchaseOrderItem;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VendorProcurementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = $this->createRestaurant('PO House', 'po-house');
        $this->otherRestaurant = $this->createRestaurant('Foreign House', 'foreign-house');
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'PO Owner',
            'email' => 'po-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Vendor Procurement Test',
            'code' => 'VENDOR-PROCUREMENT-TEST',
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
        $this->vendor = $this->createVendor($this->restaurant, 'VND000201');
    }

    public function test_purchase_order_totals_are_recalculated_and_state_transitions_are_restricted(): void
    {
        $response = $this->post(route('restaurant.vendors.purchase-orders.store'), $this->orderData([
            'subtotal' => '0.01',
            'grand_total' => '0.01',
        ]));
        $order = VendorPurchaseOrder::query()->firstOrFail();
        $response->assertRedirect(route('restaurant.vendors.purchase-orders.show', $order));

        $this->assertSame('PO000001', $order->po_number);
        $this->assertSame('25.00', $order->subtotal);
        $this->assertSame('1.00', $order->discount_total);
        $this->assertSame('2.40', $order->tax_total);
        $this->assertSame('26.40', $order->grand_total);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'target_type' => 'vendor_purchase_order',
            'target_id' => $order->id,
            'action' => 'purchase_order_created',
        ]);

        $this->post(route('restaurant.vendors.purchase-orders.confirm', $order))->assertRedirect();
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->get(route('restaurant.vendors.purchase-orders.edit', $order))->assertNotFound();
        $this->post(route('restaurant.vendors.purchase-orders.cancel', $order))->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_partial_grn_is_idempotent_and_cannot_exceed_remaining_quantity(): void
    {
        $this->post(route('restaurant.vendors.purchase-orders.store'), $this->orderData([
            'items' => [$this->itemData(['quantity' => '10.000', 'unit_price' => '5.00'])],
        ]))->assertRedirect();
        $order = VendorPurchaseOrder::query()->firstOrFail();
        $this->post(route('restaurant.vendors.purchase-orders.confirm', $order))->assertRedirect();
        $item = VendorPurchaseOrderItem::query()->firstOrFail();

        $receiptPayload = [
            'idempotency_key' => 'receipt-token-001',
            'received_date' => today()->format('Y-m-d'),
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => '4.000',
                'rejected_quantity' => '1.000',
            ]],
        ];
        $this->post(route('restaurant.vendors.goods-received.store', $order), $receiptPayload)->assertRedirect();
        $firstReceipt = VendorGoodsReceipt::query()->firstOrFail();
        $this->assertSame('GRN000001', $firstReceipt->grn_number);
        $this->assertDatabaseHas('vendor_goods_receipt_items', [
            'goods_receipt_id' => $firstReceipt->id,
            'previously_received_quantity' => '0.000',
            'received_quantity' => '4.000',
            'rejected_quantity' => '1.000',
            'accepted_quantity' => '3.000',
        ]);
        $this->assertSame('partially_received', $order->fresh()->status);

        $this->post(route('restaurant.vendors.goods-received.store', $order), $receiptPayload)->assertRedirect();
        $this->assertDatabaseCount('vendor_goods_receipts', 1);

        $this->from(route('restaurant.vendors.goods-received.create', $order))
            ->post(route('restaurant.vendors.goods-received.store', $order), [
                ...$receiptPayload,
                'idempotency_key' => 'receipt-token-too-large',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => '7.000',
                    'rejected_quantity' => '0.000',
                ]],
            ])
            ->assertRedirect(route('restaurant.vendors.goods-received.create', $order))
            ->assertSessionHasErrors('items');
        $this->assertDatabaseCount('vendor_goods_receipts', 1);

        $this->post(route('restaurant.vendors.goods-received.store', $order), [
            ...$receiptPayload,
            'idempotency_key' => 'receipt-token-002',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'received_quantity' => '6.000',
                'rejected_quantity' => '0.000',
            ]],
        ])->assertRedirect();
        $this->assertSame('received', $order->fresh()->status);
    }

    public function test_goods_receipt_posts_only_after_explicit_inventory_mapping_and_is_idempotent(): void
    {
        $this->post(route('restaurant.vendors.purchase-orders.store'), $this->orderData([
            'items' => [$this->itemData(['quantity' => '10.000', 'unit_price' => '5.00', 'discount_amount' => '0.00', 'tax_rate' => '0.00'])],
        ]))->assertRedirect();
        $order = VendorPurchaseOrder::query()->firstOrFail();
        $this->post(route('restaurant.vendors.purchase-orders.confirm', $order))->assertRedirect();
        $purchaseLine = $order->items()->firstOrFail();

        $unit = InventoryUnit::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Kilogram',
            'abbreviation' => 'kg',
            'dimension' => 'mass',
            'status' => 'active',
        ]);
        $category = InventoryCategory::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Produce',
            'status' => 'active',
        ]);
        $location = InventoryLocation::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'status' => 'active',
        ]);
        $inventoryItem = InventoryItem::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'item_code' => 'INV000001',
            'name' => 'Tomatoes',
            'reorder_level' => '1.000000',
            'reorder_quantity' => '5.000000',
            'standard_cost' => '5.000000',
            'tax_rate' => '0.00',
            'status' => 'active',
        ]);

        $payload = [
            'idempotency_key' => 'grn-inventory-001',
            'received_date' => today()->format('Y-m-d'),
            'items' => [[
                'purchase_order_item_id' => $purchaseLine->id,
                'received_quantity' => '4.000',
                'rejected_quantity' => '0.000',
                'batch_number' => '',
            ]],
        ];
        $this->post(route('restaurant.vendors.goods-received.store', $order), $payload)->assertRedirect();
        $receipt = VendorGoodsReceipt::query()->firstOrFail();
        $this->assertDatabaseMissing('inventory_movements', ['source_type' => 'vendor_grn', 'source_id' => $receipt->id]);

        $this->get(route('restaurant.vendors.goods-received.show', $receipt))
            ->assertOk()
            ->assertSee('Inventory posting is pending')
            ->assertSee('Configure vendor item mappings');
        DB::table('inventory_vendor_item_mappings')->insert([
            'restaurant_id' => $this->restaurant->id,
            'purchase_order_item_id' => $purchaseLine->id,
            'inventory_item_id' => $inventoryItem->id,
            'location_id' => $location->id,
            'unit_id' => $unit->id,
            'base_quantity_per_unit' => '1.000000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('restaurant.vendors.goods-received.inventory.post', $receipt))->assertRedirect();
        $this->post(route('restaurant.vendors.goods-received.inventory.post', $receipt))->assertRedirect();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(0, BigDecimal::of((string) DB::table('inventory_stock_balances')
            ->where('restaurant_id', $this->restaurant->id)
            ->where('inventory_item_id', $inventoryItem->id)
            ->where('location_id', $location->id)->value('quantity'))->compareTo('4'));
    }

    public function test_goods_receipt_form_can_leave_unreceived_lines_at_zero(): void
    {
        $this->post(route('restaurant.vendors.purchase-orders.store'), $this->orderData([
            'items' => [
                $this->itemData(['item_name' => 'Tomatoes', 'quantity' => '5.000']),
                $this->itemData(['item_name' => 'Onions', 'quantity' => '8.000']),
            ],
        ]))->assertRedirect();
        $order = VendorPurchaseOrder::query()->firstOrFail();
        $this->post(route('restaurant.vendors.purchase-orders.confirm', $order))->assertRedirect();
        $lines = $order->items()->orderBy('id')->get();

        $this->post(route('restaurant.vendors.goods-received.store', $order), [
            'idempotency_key' => 'grn-one-line-only',
            'received_date' => today()->format('Y-m-d'),
            'items' => [
                ['purchase_order_item_id' => $lines[0]->id, 'received_quantity' => '2.000', 'rejected_quantity' => '0.000'],
                ['purchase_order_item_id' => $lines[1]->id, 'received_quantity' => '0.000', 'rejected_quantity' => '0.000'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('vendor_goods_receipt_items', 1);
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertDatabaseCount('vendor_goods_receipts', 1);
    }

    public function test_purchase_order_and_receipt_routes_reject_foreign_tenant_ids(): void
    {
        $foreignVendor = $this->createVendor($this->otherRestaurant, 'VND000202');
        $foreignOrder = $this->otherRestaurant->vendorPurchaseOrders()->create([
            'vendor_id' => $foreignVendor->id,
            'po_number' => 'PO-FOREIGN',
            'order_date' => today(),
            'status' => 'confirmed',
        ]);

        $this->get(route('restaurant.vendors.purchase-orders.show', $foreignOrder))->assertNotFound();
        $this->get(route('restaurant.vendors.goods-received.create', $foreignOrder))->assertNotFound();
    }

    private function orderData(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor->id,
            'order_date' => today()->format('Y-m-d'),
            'expected_delivery_date' => today()->addDays(2)->format('Y-m-d'),
            'notes' => 'Morning delivery',
            'items' => [$this->itemData()],
        ], $overrides);
    }

    private function itemData(array $overrides = []): array
    {
        return array_merge([
            'item_name' => 'Tomatoes',
            'quantity' => '2.500',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'discount_amount' => '1.00',
            'tax_rate' => '10.00',
        ], $overrides);
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create(['name' => $name, 'slug' => $slug, 'email' => $slug.'@example.test', 'status' => 'active']);
    }

    private function createVendor(Restaurant $restaurant, string $code): Vendor
    {
        return $restaurant->vendors()->create([
            'vendor_code' => $code,
            'name' => $code.' supplier',
            'mobile' => '+91'.str_pad((string) $restaurant->id, 10, '0', STR_PAD_LEFT),
            'status' => 'active',
            'country' => 'India',
        ]);
    }
}