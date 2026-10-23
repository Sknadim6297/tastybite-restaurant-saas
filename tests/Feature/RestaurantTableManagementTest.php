<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantFloor;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantSection;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableOccupancy;
use App\Models\RestaurantTableReservation;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantTableManagementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;
    private Restaurant $otherRestaurant;
    private User $owner;
    private RestaurantFloor $floor;
    private RestaurantSection $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = $this->createRestaurant('Table Test Restaurant', 'table-test');
        $this->otherRestaurant = $this->createRestaurant('Other Table Restaurant', 'other-table-test');
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Table Test Owner',
            'email' => 'table-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Tables POS Plan', 'code' => 'TABLES-POS', 'price' => '0.00',
            'currency' => 'INR', 'billing_period' => 'monthly', 'duration_months' => 1,
            'trial_available' => false, 'status' => 'active',
        ]);
        $plan->features()->attach(SaasFeature::where('code', 'pos')->value('id'), ['is_enabled' => true]);
        RestaurantSubscription::create([
            'restaurant_id' => $this->restaurant->id, 'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name, 'plan_code' => $plan->code, 'price' => '0.00',
            'currency' => 'INR', 'billing_period' => 'monthly', 'duration_months' => 1,
            'status' => 'active', 'is_trial' => false,
            'starts_at' => CarbonImmutable::now(config('app.timezone'))->subDay(),
            'expires_at' => CarbonImmutable::now(config('app.timezone'))->addMonth(),
        ]);
        $this->actingAs($this->owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $this->owner->getAuthPassword()),
        ]);
        $this->floor = RestaurantFloor::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Ground Floor',
            'code' => 'GF', 'display_order' => 1, 'is_active' => true,
        ]);
        $this->section = RestaurantSection::create([
            'restaurant_id' => $this->restaurant->id, 'floor_id' => $this->floor->id,
            'name' => 'Main Dining', 'code' => 'MAIN', 'is_active' => true,
        ]);
    }

    public function test_consolidated_table_navigation_and_legacy_redirects_preserve_authorized_tenant_data(): void
    {
        $table = $this->createTable('G01');
        $otherFloor = RestaurantFloor::create([
            'restaurant_id' => $this->otherRestaurant->id, 'name' => 'Other Floor',
            'code' => 'OTHER', 'is_active' => true,
        ]);
        RestaurantTable::create([
            'restaurant_id' => $this->otherRestaurant->id, 'floor_id' => $otherFloor->id,
            'table_number' => 'SECRET-TABLE', 'capacity' => 4, 'shape' => 'round',
            'type' => 'standard', 'status' => 'available', 'is_active' => true,
        ]);

        $this->get(route('restaurant.tables.overview'))->assertOk()->assertSee('Table Dashboard')->assertSee('G01')->assertDontSee('SECRET-TABLE')->assertSee('Table Dashboard')->assertSee('Manage Tables')->assertSee('Reservations')->assertSee('Table Reports');
        $this->get(route('restaurant.tables.manage', ['tab' => 'tables']))->assertOk()->assertSee('Manage Tables')->assertSee('G01')->assertDontSee('SECRET-TABLE');
        $this->get(route('restaurant.tables.reservations'))->assertOk()->assertSee('Reservations')->assertSee('G01')->assertDontSee('SECRET-TABLE');
        $this->get(route('restaurant.tables.reports'))->assertOk()->assertSee('Table Reports');
        $this->get(route('restaurant.tables.show', $table))->assertOk()->assertSee('G01');
        $this->get(route('restaurant.tables.show', 99999))->assertNotFound();

        $this->get(route('restaurant.tables.index'))->assertRedirect(route('restaurant.tables.manage', ['tab' => 'tables']));
        $this->get(route('restaurant.tables.floors'))->assertRedirect(route('restaurant.tables.manage', ['tab' => 'floors']));
        $this->get(route('restaurant.tables.operations'))->assertRedirect(route('restaurant.tables.overview', ['tab' => 'operations']));
    }

    public function test_table_pages_render_one_breadcrumb_and_one_page_title(): void
    {
        $pages = [
            [route('restaurant.tables.overview'), 'Table Dashboard'],
            [route('restaurant.tables.manage', ['tab' => 'tables']), 'Manage Tables'],
            [route('restaurant.tables.manage', ['tab' => 'floors']), 'Manage Tables'],
            [route('restaurant.tables.manage', ['tab' => 'sections']), 'Manage Tables'],
            [route('restaurant.tables.reservations'), 'Reservations'],
            [route('restaurant.tables.reports'), 'Table Reports'],
        ];

        foreach ($pages as [$url, $title]) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'aria-label="Breadcrumb"'));
            $this->assertSame(1, substr_count($html, '<h1>'.$title.'</h1>'));
            $this->assertStringContainsString(route('restaurant.dashboard'), $html);
        }

        $reservations = $this->get(route('restaurant.tables.reservations'))->assertOk();
        $reservations->assertSee('data-open-reservation-modal', false)
            ->assertSee(route('restaurant.tables.overview'), false)
            ->assertSee(route('restaurant.tables.manage', ['tab' => 'tables']), false);
        $this->assertSame(1, substr_count($reservations->getContent(), 'data-open-reservation-modal>'));
    }

    public function test_table_creation_rejects_foreign_floors_sections_and_duplicate_identifiers(): void
    {
        $this->post(route('restaurant.tables.store'), [
            'floor_id' => $this->floor->id, 'section_id' => $this->section->id,
            'table_number' => 'G01', 'capacity' => 4, 'shape' => 'round',
            'type' => 'standard', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('restaurant_tables', ['restaurant_id' => $this->restaurant->id, 'table_number' => 'G01']);

        $this->post(route('restaurant.tables.store'), [
            'floor_id' => $this->floor->id, 'section_id' => $this->section->id,
            'table_number' => 'G01', 'capacity' => 4, 'shape' => 'round',
            'type' => 'standard', 'is_active' => '1',
        ])->assertSessionHasErrors('table_number');

        $foreignFloor = RestaurantFloor::create([
            'restaurant_id' => $this->otherRestaurant->id, 'name' => 'Foreign',
            'code' => 'F', 'is_active' => true,
        ]);
        $this->post(route('restaurant.tables.store'), [
            'floor_id' => $foreignFloor->id, 'section_id' => $this->section->id,
            'table_number' => 'FOREIGN', 'capacity' => 4, 'shape' => 'round',
            'type' => 'standard',
        ])->assertSessionHasErrors('floor_id');
    }

    public function test_bulk_table_creation_is_atomic_and_archiving_preserves_history(): void
    {
        $this->post(route('restaurant.tables.bulk'), [
            'floor_id' => $this->floor->id, 'section_id' => $this->section->id,
            'prefix' => 'G', 'start' => 1, 'count' => 3, 'capacity' => 4,
        ])->assertRedirect();
        $this->assertDatabaseCount('restaurant_tables', 3);

        $this->post(route('restaurant.tables.bulk'), [
            'floor_id' => $this->floor->id, 'prefix' => 'G', 'start' => 2,
            'count' => 3, 'capacity' => 4,
        ])->assertStatus(422);
        $this->assertDatabaseCount('restaurant_tables', 3);

        $table = RestaurantTable::where('table_number', 'G01')->firstOrFail();
        $this->delete(route('restaurant.tables.archive', $table))->assertRedirect();
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('restaurant_tables')->where('id', $table->id)->value('archived_at'));
    }

    public function test_reservations_reject_overlapping_bookings_and_enforce_capacity(): void
    {
        $table = $this->createTable('G01', 4);
        $start = now(config('app.timezone'))->addDay()->setTime(18, 0)->format('Y-m-d\TH:i');
        $payload = [
            'customer_name' => 'Test Guest', 'phone' => '5550100',
            'starts_at' => $start, 'duration_minutes' => 120, 'party_size' => 4,
            'table_id' => $table->id, 'floor_id' => $this->floor->id,
        ];
        $this->post(route('restaurant.tables.reservations.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('restaurant_table_reservations', 1);

        $conflictResponse = $this->post(route('restaurant.tables.reservations.store'), $payload);
        $conflictResponse->assertStatus(409);
        $this->assertDatabaseCount('restaurant_table_reservations', 1);

        $payload['table_id'] = null;
        $payload['party_size'] = 5;
        $this->post(route('restaurant.tables.reservations.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('restaurant_table_reservations', ['party_size' => 5, 'table_id' => null]);
    }

    public function test_pos_uses_configured_table_and_starts_an_occupancy_only_after_order_creation(): void
    {
        $table = $this->createTable('G01', 4);
        $reservedTable = $this->createTable('G02', 4);
        RestaurantTableReservation::create([
            'restaurant_id' => $this->restaurant->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_id' => $reservedTable->id,
            'created_by' => $this->owner->id, 'reference' => 'POS-RESERVED-TABLE',
            'customer_name' => 'Reserved Guest', 'phone' => '5550102',
            'starts_at' => now()->addHour()->utc(), 'duration_minutes' => 120,
            'party_size' => 2, 'status' => 'confirmed',
        ]);
        $response = $this->get(route('pos.index'))->assertOk();
        $response->assertSee('Select Table &amp; Guests', false)
            ->assertSee('data-table-id="'.$table->id.'"', false)
            ->assertSee('data-status="available"', false)
            ->assertSee('data-table-id="'.$reservedTable->id.'"', false)
            ->assertSee('data-status="reserved"', false)
            ->assertSee('data-table-required="true"', false)
            ->assertDontSee('<option>8</option>', false)
            ->assertSee('data-pos-table-card', false);
        $product = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Table Test Dish',
            'price' => '100.00', 'food_type' => 'veg', 'is_available' => true,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $this->post(route('pos.orders.store'), [
            'idempotency_key' => 'table-pos-checkout-1',
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0.00', 'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'table_id' => $table->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'guest_count' => 3,
        ])->assertRedirect();

        $order = \App\Models\RestaurantPosOrder::where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertSame($table->id, $order->table_id);
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'status' => 'occupied']);
        $this->assertDatabaseHas('restaurant_table_occupancies', [
            'restaurant_id' => $this->restaurant->id, 'table_id' => $table->id,
            'pos_order_id' => $order->id, 'guest_count' => 3, 'ended_at' => null,
        ]);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_pos_hides_and_rejects_tables_on_inactive_floors(): void
    {
        $table = $this->createTable('G01', 4);
        $this->floor->update(['is_active' => false]);
        $this->get(route('pos.index'))->assertOk()->assertDontSee('G01');
        $product = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Inactive Floor Dish',
            'price' => '100.00', 'food_type' => 'veg', 'is_available' => true,
            'is_active' => true, 'sort_order' => 0,
        ]);

        $this->post(route('pos.orders.store'), [
            'idempotency_key' => 'inactive-floor-pos-checkout',
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0.00', 'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'table_id' => $table->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'guest_count' => 2,
        ])->assertStatus(409);

        $this->assertDatabaseCount('restaurant_pos_orders', 0);
        $this->assertDatabaseCount('restaurant_table_occupancies', 0);
    }

    public function test_bulk_creation_rejects_identifiers_preserved_by_archived_tables(): void
    {
        $table = $this->createTable('G01');
        $this->delete(route('restaurant.tables.archive', $table))->assertRedirect();

        $this->post(route('restaurant.tables.bulk'), [
            'floor_id' => $this->floor->id, 'prefix' => 'G', 'start' => 1,
            'count' => 1, 'capacity' => 4,
        ])->assertStatus(422);

        $this->assertDatabaseCount('restaurant_tables', 1);
    }

    public function test_report_export_respects_date_and_dine_in_scope(): void
    {
        $table = $this->createTable('G01');
        foreach ([
            ['number' => 'REPORT-TODAY', 'total' => '100.00', 'created_at' => now()],
            ['number' => 'REPORT-OLD', 'total' => '200.00', 'created_at' => now()->subDays(10)],
        ] as $orderData) {
            RestaurantPosOrder::create([
                'restaurant_id' => $this->restaurant->id, 'created_by' => $this->owner->id,
                'order_number' => $orderData['number'], 'status' => 'completed',
                'idempotency_key' => strtolower($orderData['number']), 'order_type' => 'dine_in',
                'table_number' => $table->table_number, 'floor_id' => $this->floor->id,
                'section_id' => $this->section->id, 'table_id' => $table->id,
                'original_table_id' => $table->id, 'guest_count' => 2,
                'subtotal' => $orderData['total'], 'discount' => '0.00', 'tax' => '0.00', 'total' => $orderData['total'],
                'payment_status' => 'unpaid', 'fulfillment_status' => 'completed',
            ]);
            if ($orderData['number'] === 'REPORT-OLD') {
                \Illuminate\Support\Facades\DB::table('restaurant_pos_orders')
                    ->where('order_number', $orderData['number'])
                    ->update(['created_at' => $orderData['created_at']]);
            }
        }
        RestaurantPosOrder::create([
            'restaurant_id' => $this->restaurant->id, 'created_by' => $this->owner->id,
            'order_number' => 'REPORT-TAKEAWAY', 'status' => 'completed',
            'idempotency_key' => 'report-takeaway', 'order_type' => 'takeaway',
            'table_number' => $table->table_number, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_id' => $table->id,
            'original_table_id' => $table->id, 'guest_count' => 2,
            'subtotal' => '400.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '400.00',
            'payment_status' => 'unpaid', 'fulfillment_status' => 'completed',
        ]);

        $response = $this->get(route('restaurant.tables.reports.export', ['period' => 'today', 'table' => $table->id]))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringNotContainsString('300.00', $csv);
    }

    public function test_merged_party_can_start_one_order_and_completion_releases_all_tables_to_cleaning(): void
    {
        $first = $this->createTable('G01', 4);
        $second = $this->createTable('G02', 4);
        $this->post(route('restaurant.tables.merge'), [
            'table_ids' => [$first->id, $second->id], 'guest_count' => 6,
        ])->assertRedirect();
        $this->assertDatabaseHas('restaurant_tables', ['id' => $first->id, 'status' => 'occupied']);
        $this->assertDatabaseHas('restaurant_table_groups', ['restaurant_id' => $this->restaurant->id, 'guest_count' => 6, 'ended_at' => null]);

        $product = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Group Dinner',
            'price' => '100.00', 'food_type' => 'veg', 'is_available' => true,
            'is_active' => true, 'sort_order' => 0,
        ]);
        $this->post(route('pos.orders.store'), [
            'idempotency_key' => 'group-pos-checkout-1',
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0.00', 'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'table_id' => $first->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'guest_count' => 6,
        ])->assertRedirect();

        $order = RestaurantPosOrder::where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertSame(2, RestaurantTableOccupancy::where('pos_order_id', $order->id)->whereNull('ended_at')->count());
        foreach (['confirmed', 'preparing', 'ready', 'served', 'completed'] as $status) {
            $response = $this->patch(route('restaurant.orders.status', $order), ['status' => $status]);
            $response->assertRedirect();
        }
        $this->assertSame(0, RestaurantTableOccupancy::where('pos_order_id', $order->id)->whereNull('ended_at')->count());
        $this->assertDatabaseHas('restaurant_tables', ['id' => $first->id, 'status' => 'cleaning']);
        $this->assertDatabaseHas('restaurant_tables', ['id' => $second->id, 'status' => 'cleaning']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_table_transfer_preserves_original_order_location_and_records_transfer_history(): void
    {
        $source = $this->createTable('G01', 4);
        $destination = $this->createTable('G02', 4);
        $order = RestaurantPosOrder::create([
            'restaurant_id' => $this->restaurant->id, 'created_by' => $this->owner->id,
            'order_number' => 'TRANSFER-ORDER', 'status' => 'completed',
            'idempotency_key' => 'transfer-order-key', 'order_type' => 'dine_in',
            'table_number' => $source->table_number, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_id' => $source->id,
            'original_table_id' => $source->id, 'guest_count' => 3,
            'subtotal' => '100.00', 'discount' => '0.00', 'tax' => '5.00', 'total' => '105.00',
            'payment_status' => 'unpaid', 'fulfillment_status' => 'preparing', 'completed_at' => now(),
        ]);
        $source->update(['status' => 'occupied']);
        RestaurantTableOccupancy::create([
            'restaurant_id' => $this->restaurant->id, 'table_id' => $source->id,
            'pos_order_id' => $order->id, 'started_by' => $this->owner->id,
            'guest_count' => 3, 'started_at' => now(),
        ]);

        $this->post(route('restaurant.tables.transfer'), [
            'source_table_id' => $source->id, 'destination_table_id' => $destination->id,
            'reason' => 'Guest requested another location',
        ])->assertRedirect();

        $this->assertDatabaseHas('restaurant_pos_orders', [
            'id' => $order->id, 'table_id' => $destination->id,
            'original_table_id' => $source->id, 'table_number' => 'G02',
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('restaurant_table_transfers', [
            'restaurant_id' => $this->restaurant->id, 'source_table_id' => $source->id,
            'destination_table_id' => $destination->id, 'pos_order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('restaurant_tables', ['id' => $source->id, 'status' => 'cleaning']);
        $this->assertDatabaseHas('restaurant_tables', ['id' => $destination->id, 'status' => 'occupied']);
    }

    public function test_reservation_seating_creates_an_occupancy_and_completion_starts_cleaning(): void
    {
        $table = $this->createTable('G01', 4);
        $reservation = RestaurantTableReservation::create([
            'restaurant_id' => $this->restaurant->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_id' => $table->id,
            'created_by' => $this->owner->id, 'reference' => 'RSV-SEATED-1',
            'customer_name' => 'Seated Guest', 'phone' => '5550101',
            'starts_at' => now()->utc(), 'duration_minutes' => 120,
            'party_size' => 3, 'status' => 'confirmed',
        ]);

        $this->patch(route('restaurant.tables.reservations.status', $reservation), ['status' => 'seated'])->assertRedirect();
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'status' => 'occupied']);
        $this->assertDatabaseHas('restaurant_table_occupancies', ['table_id' => $table->id, 'guest_count' => 3, 'ended_at' => null]);
        $this->patch(route('restaurant.tables.reservations.status', $reservation), ['status' => 'completed'])->assertRedirect();
        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id, 'status' => 'cleaning']);
        $this->assertDatabaseHas('restaurant_table_reservations', ['id' => $reservation->id, 'status' => 'completed']);
    }

    public function test_pos_attaches_first_order_to_an_already_seated_reservation(): void
    {
        $table = $this->createTable('G01', 4);
        $reservation = RestaurantTableReservation::create([
            'restaurant_id' => $this->restaurant->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_id' => $table->id,
            'created_by' => $this->owner->id, 'reference' => 'RSV-POS-1',
            'customer_name' => 'POS Guest', 'phone' => '5550102',
            'starts_at' => now()->utc(), 'duration_minutes' => 120,
            'party_size' => 3, 'status' => 'confirmed',
        ]);
        $this->patch(route('restaurant.tables.reservations.status', $reservation), ['status' => 'seated'])->assertRedirect();
        $this->get(route('pos.index'))->assertOk()->assertSee('G01')->assertSee('seated party');
        $occupancyId = RestaurantTableOccupancy::where('table_id', $table->id)->whereNull('ended_at')->value('id');
        $product = RestaurantMenuItem::create([
            'restaurant_id' => $this->restaurant->id, 'name' => 'Reservation POS Dish',
            'price' => '100.00', 'food_type' => 'veg', 'is_available' => true,
            'is_active' => true, 'sort_order' => 0,
        ]);

        $this->post(route('pos.orders.store'), [
            'idempotency_key' => 'seated-reservation-pos-order',
            'items_json' => json_encode([['menu_item_id' => $product->id, 'quantity' => 1]], JSON_THROW_ON_ERROR),
            'discount_percent' => '0.00', 'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'table_id' => $table->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'guest_count' => 3,
        ])->assertRedirect();

        $order = RestaurantPosOrder::where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertSame(1, RestaurantTableOccupancy::where('table_id', $table->id)->count());
        $this->assertDatabaseHas('restaurant_table_occupancies', [
            'id' => $occupancyId, 'table_id' => $table->id, 'pos_order_id' => $order->id,
            'guest_count' => 3, 'ended_at' => null,
        ]);
    }

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create(['name' => $name, 'slug' => $slug, 'email' => $slug.'@example.test', 'status' => 'active']);
    }

    private function createTable(string $number, int $capacity = 4): RestaurantTable
    {
        return RestaurantTable::create([
            'restaurant_id' => $this->restaurant->id, 'floor_id' => $this->floor->id,
            'section_id' => $this->section->id, 'table_number' => $number, 'capacity' => $capacity,
            'shape' => 'rectangle', 'type' => 'standard', 'status' => 'available', 'is_active' => true,
        ]);
    }
}
