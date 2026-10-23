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
            ->assertSee(route('restaurant.vendors.index'))
            ->assertSee('bi bi-speedometer2', false)
            ->assertSee('bi bi-people', false)
            ->assertSee('bi bi-person-plus', false)
            ->assertSee('bi bi-clipboard', false)
            ->assertSee('bi bi-box-seam', false);

        $this->get(route('restaurant.vendors.index'))
            ->assertOk()
            ->assertSee('All vendors')
            ->assertSee('No vendors found')
            ->assertSee('data-auto-filter', false);
    }

    public function test_vendor_creation_generates_scoped_code_without_upi_or_removed_fields(): void
    {
        $this->post(route('restaurant.vendors.store'), $this->vendorData([
            'upi_id' => '',
            'account_number' => '123456789012',
        ]))->assertRedirect();

        $vendor = Vendor::query()->where('restaurant_id', $this->restaurant->id)->firstOrFail();
        $this->assertSame('VND000001', $vendor->vendor_code);
        $this->assertNull($vendor->upi_id);
        $this->assertSame('123456789012', $vendor->account_number);
        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id, 'account_number' => '123456789012']);
        $this->assertDatabaseMissing('vendor_financial_adjustments', ['vendor_id' => $vendor->id]);
        $this->assertSame(0, app(VendorAccountingService::class)->balancesForRestaurant($this->restaurant->id)[$vendor->id]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'restaurant_id' => $this->restaurant->id,
            'target_type' => 'vendor',
            'target_id' => $vendor->id,
            'action' => 'vendor_created',
        ]);

        $this->get(route('restaurant.vendors.show', $vendor))
            ->assertOk()
            ->assertSee('****9012')
            ->assertSee('Not Provided')
            ->assertDontSee('123456789012');
    }

    public function test_vendor_form_omits_removed_fields_and_includes_all_indian_states(): void
    {
        $this->get(route('restaurant.vendors.create'))
            ->assertOk()
            ->assertSee('Basic Information')
            ->assertSee('Contact Information')
            ->assertSee('Address Details')
            ->assertSee('Bank &amp; Payment Details', false)
            ->assertSee('placeholder="Select State"', false)
            ->assertSee('value="Maharashtra"', false)
            ->assertSee('value="Dadra and Nagar Haveli and Daman and Diu"', false)
            ->assertDontSee('name="business_name"', false)
            ->assertDontSee('name="gstin"', false)
            ->assertDontSee('name="registration_number"', false)
            ->assertDontSee('name="credit_limit"', false)
            ->assertDontSee('name="opening_balance"', false);
    }

    public function test_vendor_state_validation_shows_a_clear_error_for_unknown_values(): void
    {
        $this->from(route('restaurant.vendors.create'))
            ->post(route('restaurant.vendors.store'), $this->vendorData(['state' => 'Atlantis']))
            ->assertRedirect(route('restaurant.vendors.create'))
            ->assertSessionHasErrors('state');

        $this->get(route('restaurant.vendors.create'))
            ->assertSee('Select a valid Indian state or union territory.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_legacy_vendor_profile_values_are_preserved_and_state_can_be_updated(): void
    {
        $vendor = $this->restaurant->vendors()->create(array_merge($this->vendorData([
            'state' => 'Legacy State Name',
            'upi_id' => 'fresh@upi',
        ]), [
            'business_name' => 'Historic Business Name',
            'gstin' => '29ABCDE1234F1Z5',
            'registration_number' => 'REG-12345',
            'credit_limit' => '2500.00',
        ]));

        $this->get(route('restaurant.vendors.edit', $vendor))
            ->assertOk()
            ->assertSee('value="Legacy State Name"', false)
            ->assertSee('value="fresh@upi"', false)
            ->assertDontSee('Historic Business Name');
        $this->get(route('restaurant.vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Legacy State Name')
            ->assertSee('fresh@upi')
            ->assertDontSee('Historic Business Name')
            ->assertDontSee('29ABCDE1234F1Z5')
            ->assertDontSee('REG-12345')
            ->assertDontSee('2500.00');

        $this->put(route('restaurant.vendors.update', $vendor), $this->vendorData([
            'state' => 'Maharashtra',
            'upi_id' => '',
        ]))->assertRedirect(route('restaurant.vendors.show', $vendor));

        $updated = $vendor->fresh();
        $this->assertSame('Maharashtra', $updated->state);
        $this->assertNull($updated->upi_id);
        $this->assertSame('Historic Business Name', $updated->business_name);
        $this->assertSame('29ABCDE1234F1Z5', $updated->gstin);
        $this->assertSame('REG-12345', $updated->registration_number);
        $this->assertSame('2500.00', $updated->credit_limit);
    }

    public function test_existing_opening_payable_ledger_remains_independent_of_vendor_form(): void
    {
        $vendor = app(\App\Services\VendorManagementService::class)->create($this->owner, $this->vendorData([
            'opening_balance' => '125.50',
        ]));

        $this->assertDatabaseHas('vendor_financial_adjustments', [
            'restaurant_id' => $this->restaurant->id,
            'vendor_id' => $vendor->id,
            'entry_type' => 'opening_payable',
            'amount' => '125.50',
        ]);
        $this->assertSame(12550, app(VendorAccountingService::class)->balancesForRestaurant($this->restaurant->id)[$vendor->id]);
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
            'pan_number' => 'ABCDE1234F',
            'payment_terms_days' => 15,
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