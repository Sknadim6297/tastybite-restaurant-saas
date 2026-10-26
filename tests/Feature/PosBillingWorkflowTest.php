<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosInvoice;
use App\Models\RestaurantPosKot;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosPayment;
use App\Models\RestaurantSubscription;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = $this->createRestaurant('Billing Test Restaurant', 'billing-test');
        $this->restaurant->update(['address' => '12 Spice Road', 'phone' => '5550199']);
        $this->otherRestaurant = $this->createRestaurant('Other Billing Restaurant', 'other-billing-test');
        $this->owner = $this->createOwner($this->restaurant, 'billing-owner@example.test');
        $this->createOwner($this->otherRestaurant, 'other-billing-owner@example.test');
        $this->enablePos($this->restaurant, 'billing-test-plan');
        $this->enablePos($this->otherRestaurant, 'other-billing-test-plan');
        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);
    }

    public function test_cash_confirmation_calculates_change_and_exact_tender_is_optional(): void
    {
        $product = $this->createMenuItem();

        $withChange = $this->postJson(route('pos.orders.store'), $this->payload($product, 'cash-change', 'cash', [
            'cash_received' => '200.00',
        ]))->assertOk();

        $changed = RestaurantPosInvoice::query()->where('invoice_number', $withChange->json('invoice_number'))->firstOrFail();
        $this->assertSame('105.00', $changed->total);
        $this->assertSame('200.00', $changed->cash_received);
        $this->assertSame('95.00', $changed->change_amount);
        $this->assertSame('cash', $changed->payment_method);

        $exact = $this->postJson(route('pos.orders.store'), $this->payload($product, 'cash-exact', 'cash'))
            ->assertOk();
        $exactInvoice = RestaurantPosInvoice::query()->where('invoice_number', $exact->json('invoice_number'))->firstOrFail();
        $this->assertSame('105.00', $exactInvoice->cash_received);
        $this->assertSame('0.00', $exactInvoice->change_amount);

        $this->postJson(route('pos.orders.store'), $this->payload($product, 'cash-short', 'cash', [
            'cash_received' => '10.00',
        ]))->assertStatus(422);

        $this->assertDatabaseMissing('restaurant_pos_invoices', ['invoice_number' => 'cash-short']);
        $this->assertSame(2, RestaurantPosOrder::query()->where('restaurant_id', $this->restaurant->id)->count());
    }

    public function test_upi_reference_must_be_trimmed_and_explicitly_confirmed(): void
    {
        $product = $this->createMenuItem();
        $payload = $this->payload($product, 'upi-ok', 'upi', ['upi_reference' => '  UTR 7788  ']);

        unset($payload['payment_verified']);
        $this->postJson(route('pos.orders.store'), $payload)->assertStatus(422);
        $this->assertDatabaseCount('restaurant_pos_payments', 0);

        $payload['payment_verified'] = '1';
        $payload['upi_reference'] = '   ';
        $this->postJson(route('pos.orders.store'), $payload)->assertStatus(422);
        $this->assertDatabaseCount('restaurant_pos_orders', 0);

        $payload['upi_reference'] = '  UTR 7788  ';
        $response = $this->postJson(route('pos.orders.store'), $payload)->assertOk();
        $this->assertSame('UTR 7788', RestaurantPosInvoice::query()->where('invoice_number', $response->json('invoice_number'))->value('upi_reference'));
        $this->assertSame('UTR 7788', RestaurantPosPayment::query()->value('external_reference'));
    }

    public function test_card_details_store_only_last_four_and_reference(): void
    {
        $product = $this->createMenuItem();

        $this->postJson(route('pos.orders.store'), $this->payload($product, 'card-short', 'card', [
            'card_last_four' => '12',
            'card_reference' => 'TERM-1',
        ]))->assertStatus(422);

        $this->postJson(route('pos.orders.store'), $this->payload($product, 'card-pan', 'card', [
            'card_last_four' => '4242',
            'card_reference' => '4111111111111111',
        ]))->assertStatus(422);

        $this->postJson(route('pos.orders.store'), $this->payload($product, 'card-cvv', 'card', [
            'cvv' => '123',
        ]))->assertStatus(422);
        $this->assertDatabaseCount('restaurant_pos_orders', 0);

        $response = $this->postJson(route('pos.orders.store'), $this->payload($product, 'card-ok', 'card'))->assertOk();
        $invoice = RestaurantPosInvoice::query()->where('invoice_number', $response->json('invoice_number'))->firstOrFail();
        $this->assertSame('4242', $invoice->card_last_four);
        $this->assertSame('TERM-4242', $invoice->card_reference);
        $this->assertDatabaseMissing('restaurant_pos_payments', ['card_last_four' => '4111111111111111']);
    }

    public function test_invoice_generation_is_idempotent_and_keeps_a_restaurant_snapshot(): void
    {
        $product = $this->createMenuItem();
        $payload = $this->payload($product, 'invoice-once', 'upi');

        $first = $this->postJson(route('pos.orders.store'), $payload)->assertOk();
        $second = $this->postJson(route('pos.orders.store'), $payload)->assertOk();

        $this->assertSame($first->json('invoice_number'), $second->json('invoice_number'));
        $this->assertTrue($second->json('already_completed'));
        $this->assertSame(1, RestaurantPosInvoice::query()->count());
        $this->assertSame(1, RestaurantPosPayment::query()->count());
        $this->assertSame(1, RestaurantPosOrder::query()->where('restaurant_id', $this->restaurant->id)->count());

        $this->restaurant->update(['name' => 'Renamed Kitchen', 'address' => '99 New Street']);
        $this->get(route('restaurant.orders.invoice', $first->json('order_id')))
            ->assertOk()
            ->assertSee('Billing Test Restaurant')
            ->assertSee('12 Spice Road')
            ->assertSee($first->json('invoice_number'))
            ->assertSee('₹105.00')
            ->assertDontSee('Renamed Kitchen')
            ->assertDontSee('99 New Street');
    }

    public function test_kot_reprint_does_not_create_another_ticket_and_hides_prices(): void
    {
        $product = $this->createMenuItem();
        $payload = [
            'idempotency_key' => 'kot-order-1',
            'items_json' => json_encode([[
                'menu_item_id' => $product->id,
                'quantity' => 2,
                'instructions' => 'No onion',
            ]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0',
            'order_type' => 'takeaway',
        ];

        $first = $this->postJson(route('pos.kot.store'), $payload)->assertOk();
        $second = $this->postJson(route('pos.kot.store'), $payload)->assertOk();

        $this->assertSame($first->json('kot_number'), $second->json('kot_number'));
        $this->assertSame(1, RestaurantPosKot::query()->count());
        $this->assertSame(1, RestaurantPosKot::query()->value('reprint_count'));
        $this->assertFalse($first->json('reprinted'));
        $this->assertTrue($second->json('reprinted'));

        $this->get(route('restaurant.orders.kot', $first->json('order_id')))
            ->assertOk()
            ->assertSee($first->json('kot_number'))
            ->assertSee($first->json('order_number'))
            ->assertSee('Billing Test Restaurant')
            ->assertSee('No onion')
            ->assertSee('Takeaway')
            ->assertDontSee('₹')
            ->assertDontSee('Grand')
            ->assertDontSee('Tax');

        $sale = $this->postJson(route('pos.orders.store'), $this->payload($product, 'kot-order-1', 'cash', [
            'resumed_order_id' => $first->json('order_id'),
            'items_json' => $payload['items_json'],
        ]))->assertOk();

        $this->assertSame(1, RestaurantPosOrder::query()->where('restaurant_id', $this->restaurant->id)->count());
        $this->assertSame(1, RestaurantPosKot::query()->count());
        $this->assertSame('completed', RestaurantPosOrder::query()->find($sale->json('order_id'))->status);

        $this->restaurant->update(['name' => 'Renamed Kitchen']);
        $this->get(route('restaurant.orders.kot', $first->json('order_id')))
            ->assertOk()
            ->assertSee('Billing Test Restaurant')
            ->assertDontSee('Renamed Kitchen');
    }

    public function test_completed_invoice_is_available_from_order_history_after_a_new_login(): void
    {
        $product = $this->createMenuItem();
        $sale = $this->postJson(route('pos.orders.store'), $this->payload($product, 'history-invoice', 'upi'))->assertOk();
        $orderId = $sale->json('order_id');

        $this->get(route('restaurant.orders.all'))
            ->assertOk()
            ->assertSee($sale->json('order_number'))
            ->assertSee($sale->json('invoice_number'))
            ->assertSee('UPI')
            ->assertSee('UTR-HISTORY')
            ->assertSee('View Order')
            ->assertSee('View Invoice')
            ->assertSee('Download Invoice')
            ->assertSee('Print Invoice')
            ->assertSee('Reprint KOT');

        $this->get(route('restaurant.orders.all', ['search' => $sale->json('invoice_number')]))
            ->assertOk()
            ->assertSee($sale->json('order_number'));

        auth('web')->logout();
        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);

        $this->get(route('restaurant.orders.invoice.pdf', $orderId))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->post(route('restaurant.orders.kot.reprint', $orderId))->assertRedirect();
        $this->assertSame(1, RestaurantPosKot::query()->where('pos_order_id', $orderId)->count());
        $this->post(route('restaurant.orders.kot.reprint', $orderId))->assertRedirect();
        $this->assertSame(1, RestaurantPosKot::query()->value('reprint_count'));
    }

    public function test_invoice_and_kot_routes_stay_inside_the_restaurant_tenant(): void
    {
        $product = $this->createMenuItem();
        $sale = $this->postJson(route('pos.orders.store'), $this->payload($product, 'tenant-invoice', 'cash'))->assertOk();
        $otherOwner = User::query()->where('restaurant_id', $this->otherRestaurant->id)->firstOrFail();
        $otherOrder = RestaurantPosOrder::create([
            'restaurant_id' => $this->otherRestaurant->id,
            'created_by' => $otherOwner->id,
            'order_number' => 'OTHER-BILL-1',
            'status' => 'completed',
            'idempotency_key' => 'other-bill-1',
            'subtotal' => '10.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'total' => '10.00',
            'order_type' => 'takeaway',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'fulfillment_status' => 'pending',
            'completed_at' => now(),
        ]);

        $this->get(route('restaurant.orders.invoice', $otherOrder))->assertNotFound();
        $this->get(route('restaurant.orders.invoice.pdf', $otherOrder))->assertNotFound();
        $this->get(route('restaurant.orders.kot', $otherOrder))->assertNotFound();
        $this->post(route('restaurant.orders.kot.reprint', $otherOrder))->assertNotFound();
        $this->get(route('restaurant.orders.all'))->assertDontSee('OTHER-BILL-1');

        auth('web')->logout();
        $this->get(route('restaurant.orders.invoice.pdf', $sale->json('order_id')))->assertRedirect();
    }

    private function payload(RestaurantMenuItem $product, string $key, string $method, array $overrides = []): array
    {
        $payload = [
            'idempotency_key' => $key,
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0',
            'order_type' => 'takeaway',
            'payment_method' => $method,
            'payment_verified' => '1',
        ];
        if ($method === 'upi') {
            $payload['upi_reference'] = 'UTR-HISTORY';
        }
        if ($method === 'card') {
            $payload['card_last_four'] = '4242';
            $payload['card_reference'] = 'TERM-4242';
        }

        return array_merge($payload, $overrides);
    }

    private function createMenuItem(): RestaurantMenuItem
    {
        return RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Billing Dish',
            'price' => '100.00',
            'food_type' => 'veg',
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
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
            'name' => 'Billing Owner',
            'email' => $email,
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
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
}
