<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFinancialAdjustment;
use App\Models\VendorPurchaseOrder;
use App\Services\VendorAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class VendorManagementTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = $this->createRestaurant('North Restaurant', 'north-restaurant');
        $this->otherRestaurant = $this->createRestaurant('South Restaurant', 'south-restaurant');
        $this->owner = User::create([
            'restaurant_id' => $this->restaurant->id,
            'name' => 'North Owner',
            'email' => 'north-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Vendor Test Plan',
            'code' => 'VENDOR-TEST',
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
    }

    public function test_vendor_overview_and_listing_render_real_empty_states_and_sidebar_links(): void
    {
        $this->get(route('restaurant.vendors.overview'))
            ->assertOk()
            ->assertSee('Vendor overview')
            ->assertSee('Total vendors')
            ->assertSee('No purchase orders yet')
            ->assertSee('Vendor Management')
            ->assertSee(route('restaurant.vendors.index'));

        $this->get(route('restaurant.vendors.index'))
            ->assertOk()
            ->assertSee('All vendors')
            ->assertSee('No vendors found')
            ->assertSee('data-auto-filter', false);
    }

    public function test_vendor_creation_generates_scoped_code_and_audited_opening_payable(): void
    {
        $this->post(route('restaurant.vendors.store'), $this->vendorData([
            'opening_balance' => '125.50',
            'account_number' => '123456789012',
        ]))->assertRedirect();

        $vendor = Vendor::query()->where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertSame('VND000001', $vendor->vendor_code);
        $this->assertSame('123456789012', $vendor->account_number);
        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id, 'account_number' => '123456789012']);
        $this->assertDatabaseHas('vendor_financial_adjustments', [
            'restaurant_id' => $this->restaurant->id,
            'vendor_id' => $vendor->id,
            'entry_type' => 'opening_payable',
            'amount' => '125.50',
        ]);
        $this->assertSame(12550, app(VendorAccountingService::class)->balancesForRestaurant($this->restaurant->id)[$vendor->id]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'restaurant_id' => $this->restaurant->id,
            'target_type' => 'vendor',
            'target_id' => $vendor->id,
            'action' => 'vendor_created',
        ]);

        $this->get(route('restaurant.vendors.show', $vendor))
            ->assertOk()
            ->assertSee('****9012')
            ->assertDontSee('123456789012');
    }

    public function test_vendor_search_status_filter_and_duplicate_mobile_are_restaurant_scoped(): void
    {
        $vendor = $this->restaurant->vendors()->create($this->vendorData(['vendor_code' => 'VND000101']));
        $this->otherRestaurant->vendors()->create($this->vendorData(['vendor_code' => 'VND000102']));

        $this->get(route('restaurant.vendors.index', ['search' => 'Fresh']))
            ->assertOk()
            ->assertSee($vendor->vendor_code)
            ->assertDontSee('VND000102');
        $this->get(route('restaurant.vendors.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertDontSee($vendor->vendor_code);

        $this->from(route('restaurant.vendors.create'))
            ->post(route('restaurant.vendors.store'), $this->vendorData(['name' => 'Duplicate Supplier']))
            ->assertRedirect(route('restaurant.vendors.create'))
            ->assertSessionHasErrors('mobile');
    }

    public function test_vendor_records_are_tenant_scoped_and_bank_number_is_preserved_when_edit_field_is_blank(): void
    {
        $vendor = $this->restaurant->vendors()->create($this->vendorData([
            'vendor_code' => 'VND000103',
            'account_number' => '987654321098',
        ]));
        $foreignVendor = $this->otherRestaurant->vendors()->create($this->vendorData([
            'vendor_code' => 'VND000104',
            'mobile' => '+919876543211',
        ]));

        $this->get(route('restaurant.vendors.show', $foreignVendor))->assertNotFound();
        $this->put(route('restaurant.vendors.update', $foreignVendor), $this->vendorData())->assertNotFound();

        $this->put(route('restaurant.vendors.update', $vendor), $this->vendorData([
            'name' => 'Fresh Foods Updated',
            'account_number' => '',
        ]))->assertRedirect(route('restaurant.vendors.show', $vendor));
        $this->assertSame('987654321098', $vendor->fresh()->account_number);
    }

    public function test_vendor_can_be_archived_only_without_purchasing_or_financial_history(): void
    {
        $vendor = $this->restaurant->vendors()->create($this->vendorData(['vendor_code' => 'VND000105']));
        $this->delete(route('restaurant.vendors.archive', $vendor))
            ->assertRedirect(route('restaurant.vendors.index'));
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);

        $vendorWithOrder = $this->restaurant->vendors()->create($this->vendorData([
            'name' => 'Order Vendor',
            'mobile' => '+919876543212',
            'vendor_code' => 'VND000106',
        ]));
        VendorPurchaseOrder::create([
            'restaurant_id' => $this->restaurant->id,
            'vendor_id' => $vendorWithOrder->id,
            'order_date' => today(),
            'status' => 'draft',
        ]);
        $this->from(route('restaurant.vendors.index'))
            ->delete(route('restaurant.vendors.archive', $vendorWithOrder))
            ->assertRedirect(route('restaurant.vendors.index'))
            ->assertSessionHasErrors('vendor');
        $this->assertDatabaseHas('vendors', ['id' => $vendorWithOrder->id, 'deleted_at' => null]);
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

    private function vendorData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fresh Foods Supplier',
            'contact_person' => 'Asha Rao',
            'mobile' => '+919876543210',
            'alternate_mobile' => '',
            'email' => 'fresh@example.test',
            'category' => 'Produce',
            'status' => 'active',
            'business_name' => 'Fresh Foods Private Limited',
            'gstin' => '29ABCDE1234F1Z5',
            'pan_number' => 'ABCDE1234F',
            'registration_number' => '',
            'payment_terms_days' => 15,
            'credit_limit' => '10000.00',
            'address_line_1' => '10 Market Road',
            'address_line_2' => '',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
            'country' => 'India',
            'account_holder_name' => 'Fresh Foods',
            'bank_name' => 'Test Bank',
            'account_number' => '',
            'ifsc_code' => 'TEST0001234',
            'bank_branch' => 'Central',
            'upi_id' => 'fresh@upi',
            'notes' => 'Preferred morning delivery.',
        ], $overrides);
    }
}