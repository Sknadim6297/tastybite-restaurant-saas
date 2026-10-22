<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPurchaseOrder;
use Database\Seeders\VendorDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_demo_seeder_is_idempotent_and_renders_its_records_on_vendor_pages(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Test resturent',
            'slug' => 'test-resturent-demo-seeder',
            'email' => 'test-resturent@example.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Demo Restaurant Owner',
            'email' => 'demo-owner@example.test',
            'password' => 'StrongTestPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Vendor Demo Test Plan',
            'code' => 'VENDOR-DEMO-TEST',
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

        $this->actingAs($owner, 'web')->withSession([
            'restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword()),
        ]);

        app(VendorDemoSeeder::class)->run();
        app(VendorDemoSeeder::class)->run();

        $this->assertSame(10, Vendor::query()->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(8, Vendor::query()->where('restaurant_id', $restaurant->id)->where('status', 'active')->count());
        $this->assertSame(15, VendorPurchaseOrder::query()->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(8, VendorGoodsReceipt::query()->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(3, VendorPurchaseOrder::query()->where('restaurant_id', $restaurant->id)->first()->items()->count());

        $vendorName = Vendor::query()->where('restaurant_id', $restaurant->id)->orderBy('vendor_code')->value('name');
        $this->get(route('restaurant.vendors.overview'))
            ->assertOk()
            ->assertSee($vendorName)
            ->assertSee('DEMO-PO-0015')
            ->assertSee('DEMO-GRN-0008')
            ->assertSee('INR 0.00');
        $this->get(route('restaurant.vendors.index'))->assertOk()->assertSee($vendorName);
        $this->get(route('restaurant.vendors.purchase-orders.index'))->assertOk()->assertSee('DEMO-PO-0001');
        $this->get(route('restaurant.vendors.goods-received.index'))->assertOk()->assertSee('DEMO-GRN-0001');
    }
}
