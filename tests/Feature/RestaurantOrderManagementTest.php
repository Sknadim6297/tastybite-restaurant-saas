<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryUnit;
use App\Models\MenuRecipe;
use App\Models\MenuRecipeIngredient;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderItem;
use App\Models\RestaurantPosPayment;
use App\Models\RestaurantPosRefund;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestaurantOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    private RestaurantPosOrder $otherOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = $this->createRestaurant('Orders Test Restaurant', 'orders-test');
        $this->otherRestaurant = $this->createRestaurant('Other Orders Restaurant', 'other-orders-test');
        $this->owner = $this->createOwner($this->restaurant, 'orders-owner@example.test');
        $otherOwner = $this->createOwner($this->otherRestaurant, 'other-orders-owner@example.test');
        $this->enablePos($this->restaurant, 'orders-test-plan');
        $this->enablePos($this->otherRestaurant, 'other-orders-test-plan');

        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);

        $this->otherOrder = $this->createOrder($otherOwner, 'ORDER-OTHER');
    }

    public function test_order_pages_and_receipt_are_tenant_scoped(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-OWN');

        $this->get(route('restaurant.orders.overview'))->assertOk()->assertSee('Order Overview');
        $this->get(route('restaurant.orders.all'))->assertOk()->assertSee('ORDER-OWN')->assertDontSee('ORDER-OTHER');
        $this->get(route('restaurant.orders.dine-in'))->assertOk();
        $this->get(route('restaurant.orders.fulfillment', ['type' => 'takeaway']))->assertOk();
        $this->get(route('restaurant.orders.billing'))->assertOk();
        $this->get(route('restaurant.orders.reports'))->assertOk();
        $this->get(route('restaurant.orders.show', $order))->assertOk()->assertSee('ORDER-OWN');
        $this->get(route('restaurant.orders.receipt', $order))->assertOk()->assertSee('ORDER-OWN');
        $this->get(route('restaurant.orders.show', $this->otherOrder))->assertNotFound();
    }

    public function test_ajax_filters_combine_search_and_status_without_exposing_another_restaurant(): void
    {
        $matching = $this->createOrder($this->owner, 'ORDER-FILTER-MATCH', 'pending');
        $this->createOrder($this->owner, 'ORDER-FILTER-OTHER', 'ready');

        $response = $this->getJson(route('restaurant.orders.all', [
            'search' => 'FILTER',
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]))->assertOk();

        $this->assertStringContainsString($matching->order_number, $response->json('html'));
        $this->assertStringNotContainsString('ORDER-FILTER-OTHER', $response->json('html'));
        $this->assertStringNotContainsString('ORDER-OTHER', $response->json('html'));
    }

    public function test_fulfillment_updates_allow_only_the_next_status_and_record_history(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-STATUS', 'pending');

        $this->patch(route('restaurant.orders.status', $order), ['status' => 'confirmed'])
            ->assertRedirect();
        $this->assertDatabaseHas('restaurant_pos_orders', [
            'id' => $order->id,
            'restaurant_id' => $this->restaurant->id,
            'fulfillment_status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('restaurant_pos_order_status_history', [
            'pos_order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
        ]);

        $this->patch(route('restaurant.orders.status', $order), ['status' => 'ready'])
            ->assertStatus(422);
    }

    public function test_manual_refunds_are_bounded_by_recorded_payment_and_never_change_order_totals(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-REFUND', 'pending', 'paid');
        $payment = RestaurantPosPayment::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $order->id,
            'recorded_by' => $this->owner->id,
            'payment_reference' => 'PAY-REFUND-'.$order->id,
            'idempotency_key' => 'refund-test:'.$order->id,
            'amount' => '100.00',
            'method' => 'cash',
            'status' => 'recorded',
            'recorded_at' => now(),
            'notes' => 'Test payment',
        ]);

        $this->post(route('restaurant.orders.refund', $order), [
            'amount' => '40.00',
            'reason' => 'Customer returned item',
        ])->assertRedirect();
        $this->assertDatabaseHas('restaurant_pos_refunds', [
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => '40.00',
            'status' => 'recorded',
        ]);

        $this->post(route('restaurant.orders.refund', $order), [
            'amount' => '61.00',
            'reason' => 'Refund exceeds payment balance',
        ])->assertStatus(422);

        $this->assertDatabaseHas('restaurant_pos_orders', [
            'id' => $order->id,
            'total' => '100.00',
            'payment_status' => 'partially_refunded',
        ]);
    }

    public function test_pos_completes_with_a_selected_method_without_payment_confirmation(): void
    {
        $product = $this->createMenuItem();

        foreach (['cash', 'card', 'upi'] as $method) {
            $key = 'confirmed-'.$method;
            $this->post(route('pos.orders.store'), $this->posPayload($product, $key, $method))
                ->assertRedirect(route('pos.orders.index'));

            $order = RestaurantPosOrder::query()
                ->where('restaurant_id', $this->restaurant->id)
                ->where('idempotency_key', $key)
                ->firstOrFail();

            $this->assertSame('completed', $order->status);
            $this->assertSame('paid', $order->payment_status);
            $this->assertSame($method, $order->payment_method);
            $this->assertDatabaseHas('restaurant_pos_payments', [
                'restaurant_id' => $this->restaurant->id,
                'pos_order_id' => $order->id,
                'method' => $method,
                'amount' => $order->total,
                'status' => 'recorded',
            ]);
        }
    }

    public function test_legacy_payment_confirmation_and_order_notes_are_ignored(): void
    {
        $product = $this->createMenuItem();
        $key = 'unconfirmed-card';

        $payload = $this->posPayload($product, $key, 'card');
        $payload['payment_confirmed'] = '1';
        $payload['notes'] = 'This note must not be stored';

        $this->post(route('pos.orders.store'), $payload)
            ->assertRedirect(route('pos.orders.index'));

        $order = RestaurantPosOrder::query()->where('idempotency_key', $key)->firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('card', $order->payment_method);
        $this->assertNull($order->notes);
        $this->assertDatabaseHas('restaurant_pos_payments', [
            'pos_order_id' => $order->id,
            'method' => 'card',
            'status' => 'recorded',
        ]);
    }

    public function test_pos_checkout_does_not_consume_active_recipe_stock(): void
    {
        $unit = InventoryUnit::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Kilogram',
            'abbreviation' => 'kg',
            'dimension' => 'mass',
            'status' => 'active',
        ]);
        $category = InventoryCategory::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Ingredients',
            'status' => 'active',
        ]);
        $location = InventoryLocation::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'status' => 'active',
        ]);
        $ingredient = InventoryItem::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'default_location_id' => $location->id,
            'item_code' => 'ING000001',
            'name' => 'Rice',
            'status' => 'active',
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
        $product = $this->createMenuItem();
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
            'inventory_item_id' => $ingredient->id,
            'unit_id' => $unit->id,
            'quantity' => '0.250000',
            'base_quantity' => '0.250000',
        ]);

        $this->post(route('pos.orders.store'), $this->posPayload($product, 'recipe-no-deduction', 'cash'))
            ->assertRedirect(route('pos.orders.index'));

        $order = RestaurantPosOrder::query()->where('idempotency_key', 'recipe-no-deduction')->firstOrFail();
        $orderItem = RestaurantPosOrderItem::query()->where('pos_order_id', $order->id)->firstOrFail();
        $this->assertSame('completed', $order->status);
        $this->assertNull($orderItem->menu_recipe_id);
        $this->assertDatabaseCount('restaurant_pos_order_item_ingredients', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_repeated_pos_submission_with_same_idempotency_key_does_not_duplicate_payment(): void
    {
        $product = $this->createMenuItem();
        $payload = $this->posPayload($product, 'double-submit', 'upi');

        $this->post(route('pos.orders.store'), $payload)->assertRedirect(route('pos.orders.index'));
        $this->post(route('pos.orders.store'), $payload)->assertRedirect(route('pos.orders.index'));

        $order = RestaurantPosOrder::query()->where('idempotency_key', 'double-submit')->firstOrFail();
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, RestaurantPosOrder::query()->where('idempotency_key', 'double-submit')->count());
    }

    public function test_existing_unpaid_order_can_be_reconciled_only_with_explicit_full_payment_verification(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-RECONCILE', 'pending');
        $payload = [
            'method' => 'upi',
            'verification_note' => 'Verified UPI transaction reference 12345',
            'confirmed' => '1',
        ];

        $this->post(route('restaurant.orders.payment.reconcile', $order), $payload)->assertRedirect();
        $this->assertDatabaseHas('restaurant_pos_orders', [
            'id' => $order->id,
            'payment_status' => 'paid',
            'payment_method' => 'upi',
        ]);
        $this->assertDatabaseHas('restaurant_pos_payments', [
            'pos_order_id' => $order->id,
            'amount' => $order->total,
            'method' => 'upi',
        ]);

        $this->post(route('restaurant.orders.payment.reconcile', $order), $payload)->assertStatus(409);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_cancelled_unpaid_order_cannot_be_reconciled(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-RECONCILE-CANCELLED', 'cancelled');
        $order->update(['status' => 'cancelled']);
        $this->post(route('restaurant.orders.payment.reconcile', $order), [
            'method' => 'cash',
            'verification_note' => 'Verified from cash drawer report',
            'confirmed' => '1',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('restaurant_pos_payments', ['pos_order_id' => $order->id]);
    }

    public function test_order_detail_shows_payment_reference_method_amount_and_recorder(): void
    {
        $order = $this->createOrder($this->owner, 'ORDER-DETAIL-PAYMENT', 'pending', 'paid');
        RestaurantPosPayment::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $order->id,
            'recorded_by' => $this->owner->id,
            'payment_reference' => 'PAY-DETAIL-'.$order->id,
            'idempotency_key' => 'detail-test:'.$order->id,
            'amount' => '100.00',
            'method' => 'cash',
            'status' => 'recorded',
            'recorded_at' => now(),
        ]);

        $this->get(route('restaurant.orders.show', $order))
            ->assertOk()
            ->assertSee('PAY-DETAIL-'.$order->id)
            ->assertSee('CASH')
            ->assertSee('100.00')
            ->assertSee($this->owner->name);
    }

    public function test_reports_count_only_recorded_payments_as_net_paid_revenue(): void
    {
        $paid = $this->createOrder($this->owner, 'ORDER-REPORT-PAID', 'pending', 'paid');
        $unpaid = $this->createOrder($this->owner, 'ORDER-REPORT-UNPAID', 'pending');
        RestaurantPosPayment::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $paid->id,
            'recorded_by' => $this->owner->id,
            'payment_reference' => 'PAY-REPORT-'.$paid->id,
            'idempotency_key' => 'report-test:'.$paid->id,
            'amount' => '100.00',
            'method' => 'cash',
            'status' => 'recorded',
            'recorded_at' => now(),
        ]);
        $this->assertDatabaseMissing('restaurant_pos_payments', ['pos_order_id' => $unpaid->id]);

        $this->get(route('restaurant.orders.reports'))
            ->assertOk()
            ->assertSee('Net paid revenue')
            ->assertSee('₹100.00');
    }

    public function test_reports_use_authoritative_order_status_and_consistent_accounting_for_selected_local_dates(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        $paidOrder = $this->createOrder($this->owner, 'ORDER-REPORT-ACCURATE', 'pending', 'paid');
        $paidOrder->update([
            'order_type' => 'dine_in',
            'subtotal' => '200.00',
            'discount' => '20.00',
            'tax' => '9.00',
            'total' => '189.00',
            'created_at' => '2026-10-23 00:00:00',
        ]);
        $unpaidOrder = $this->createOrder($this->owner, 'ORDER-REPORT-UNPAID-ACCURATE');
        $unpaidOrder->update([
            'order_type' => 'takeaway',
            'subtotal' => '100.00',
            'discount' => '5.00',
            'tax' => '4.75',
            'total' => '99.75',
            'created_at' => '2026-10-23 23:59:59',
        ]);
        $cancelledOrder = $this->createOrder($this->owner, 'ORDER-REPORT-CANCELLED');
        $cancelledOrder->update([
            'status' => 'cancelled',
            'fulfillment_status' => 'cancelled',
            'subtotal' => '50.00',
            'discount' => '0.00',
            'tax' => '2.50',
            'total' => '52.50',
            'created_at' => '2026-10-24 00:00:00',
        ]);
        $this->createOrder($this->otherOwner(), 'ORDER-REPORT-OTHER-TENANT');

        $payment = RestaurantPosPayment::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $paidOrder->id,
            'recorded_by' => $this->owner->id,
            'payment_reference' => 'PAY-REPORT-ACCURATE-'.$paidOrder->id,
            'idempotency_key' => 'report-accurate:'.$paidOrder->id,
            'amount' => '189.00',
            'method' => 'upi',
            'status' => 'recorded',
            'recorded_at' => '2026-10-23 00:01:00',
        ]);
        RestaurantPosRefund::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $paidOrder->id,
            'payment_id' => $payment->id,
            'recorded_by' => $this->owner->id,
            'refund_reference' => 'REF-REPORT-'.$paidOrder->id,
            'idempotency_key' => 'report-refund:'.$paidOrder->id,
            'amount' => '40.00',
            'status' => 'recorded',
            'reason' => 'Partial customer refund',
            'recorded_at' => '2026-10-23 06:30:00',
        ]);
        RestaurantPosPayment::create([
            'restaurant_id' => $this->otherRestaurant->id,
            'pos_order_id' => $this->otherOrder->id,
            'recorded_by' => $this->otherOwner()->id,
            'payment_reference' => 'PAY-OTHER-TENANT',
            'idempotency_key' => 'report-other-tenant',
            'amount' => '999.00',
            'method' => 'cash',
            'status' => 'recorded',
            'recorded_at' => '2026-10-23 06:30:00',
        ]);

        RestaurantPosOrderItem::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $paidOrder->id,
            'item_name' => 'Report Biryani',
            'quantity' => 2,
            'unit_price' => '80.00',
        ]);
        RestaurantPosOrderItem::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $unpaidOrder->id,
            'item_name' => 'Report Rice',
            'quantity' => 1,
            'unit_price' => '100.00',
        ]);
        RestaurantPosOrderItem::create([
            'restaurant_id' => $this->restaurant->id,
            'pos_order_id' => $cancelledOrder->id,
            'item_name' => 'Cancelled Item',
            'quantity' => 10,
            'unit_price' => '50.00',
        ]);

        $response = $this->get(route('restaurant.orders.reports', [
            'period' => 'custom',
            'from' => '2026-10-23',
            'to' => '2026-10-23',
        ]))->assertOk()
            ->assertSee('data-report-metric="total-orders"', false)
            ->assertSee('data-report-metric="completed-orders"', false)
            ->assertSee('data-report-metric="cancelled-orders"', false)
            ->assertSee('data-report-metric="unpaid-completed"', false)
            ->assertSee('data-report-metric="completed-sales"', false)
            ->assertSee('data-report-metric="discounts"', false)
            ->assertSee('data-report-metric="tax"', false)
            ->assertSee('data-report-metric="average-order-value"', false)
            ->assertSee('data-report-metric="paid-revenue"', false)
            ->assertSee('₹288.75')
            ->assertSee('₹25.00')
            ->assertSee('₹13.75')
            ->assertSee('₹144.38')
            ->assertSee('₹149.00')
            ->assertSee('Dine In · 1 order')
            ->assertSee('Takeaway · 1 order')
            ->assertSee('>Completed</span><strong>2</strong>', false)
            ->assertSee('Cancelled')
            ->assertSee('UPI · 1 order')
            ->assertSee('Report Biryani · 2 sold')
            ->assertDontSee('Cancelled Item')
            ->assertDontSee('ORDER-REPORT-OTHER-TENANT')
            ->assertDontSee('₹999.00');

        $html = $response->getContent();
        $this->assertSame('3', $this->reportMetric($html, 'total-orders'));
        $this->assertSame('2', $this->reportMetric($html, 'completed-orders'));
        $this->assertSame('1', $this->reportMetric($html, 'cancelled-orders'));
        $this->assertSame('1', $this->reportMetric($html, 'unpaid-completed'));
        $this->assertStringNotContainsString('Completed</span><strong>1.00', $html);
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }

    private function createOwner(Restaurant $restaurant, string $email): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Orders Test Owner',
            'email' => $email,
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function otherOwner(): User
    {
        return User::query()->where('restaurant_id', $this->otherRestaurant->id)->firstOrFail();
    }

    private function enablePos(Restaurant $restaurant, string $code): void
    {
        $plan = SubscriptionPlan::create([
            'name' => $code,
            'code' => Str::upper($code),
            'price' => '0.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $plan->features()->attach(SaasFeature::query()->where('code', 'pos')->value('id'), [
            'is_enabled' => true,
            'limit_value' => null,
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
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);
    }

    private function createOrder(
        User $owner,
        string $number,
        string $fulfillmentStatus = 'pending',
        string $paymentStatus = 'unpaid',
    ): RestaurantPosOrder {
        return RestaurantPosOrder::create([
            'restaurant_id' => $owner->restaurant_id,
            'created_by' => $owner->id,
            'order_number' => $number,
            'status' => 'completed',
            'idempotency_key' => 'test-order:'.$number,
            'subtotal' => '100.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'total' => '100.00',
            'order_type' => 'takeaway',
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentStatus === 'paid' ? 'cash' : null,
            'fulfillment_status' => $fulfillmentStatus,
            'completed_at' => now(),
        ]);
    }

    private function createMenuItem(): RestaurantMenuItem
    {
        return RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Payment Test Item',
            'price' => '100.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function posPayload(RestaurantMenuItem $product, string $key, string $method): array
    {
        return [
            'idempotency_key' => $key,
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0',
            'order_type' => 'takeaway',
            'payment_method' => $method,
        ];
    }

    private function reportMetric(string $html, string $key): ?string
    {
        $matched = preg_match(
            '/<article[^>]*data-report-metric="'.preg_quote($key, '/').'".*?<strong>(.*?)<\/strong>/s',
            $html,
            $matches,
        );

        return $matched === 1 ? trim(strip_tags($matches[1])) : null;
    }
}
