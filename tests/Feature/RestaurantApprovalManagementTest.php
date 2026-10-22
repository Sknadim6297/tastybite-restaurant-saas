<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantApprovalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creation_always_approves_without_changing_requested_operational_status(): void
    {
        $this->actingAsSuperAdmin();
        $existingRestaurant = Restaurant::create([
            'name' => 'Legacy Pending House',
            'slug' => 'legacy-pending-house',
            'email' => 'legacy@example.test',
            'status' => 'suspended',
            'approval_status' => 'pending',
        ]);

        $this->post(route('super-admin.restaurants.store'), [
            'restaurant' => [
                'name' => 'Direct Approval House',
                'slug' => 'direct-approval-house',
                'email' => 'hello@direct-approval.test',
                'status' => 'inactive',
                'approval_status' => 'pending',
            ],
            'owner' => [
                'name' => 'Direct Approval Owner',
                'email' => 'owner@direct-approval.test',
            ],
        ])->assertSessionHas('status');

        $createdRestaurant = Restaurant::query()->where('slug', 'direct-approval-house')->firstOrFail();
        $this->assertSame('approved', $createdRestaurant->approval_status);
        $this->assertSame('inactive', $createdRestaurant->status);
        $this->assertSame('pending', $existingRestaurant->fresh()->approval_status);
        $this->assertSame('suspended', $existingRestaurant->fresh()->status);
    }

    public function test_approval_navigation_is_removed_and_legacy_listing_redirects_to_restaurants(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Restaurants')
            ->assertSee('Restaurant Owners')
            ->assertDontSee('Restaurant Approvals');

        $this->get(route('super-admin.restaurants.create'))
            ->assertOk()
            ->assertDontSee('restaurant[approval_status]')
            ->assertDontSee('Approval status');

        $this->get('/super-admin/restaurant-approvals')
            ->assertRedirect(route('super-admin.restaurants.index'));

        $this->get('/super-admin/restaurant-approvals/1')->assertNotFound();
        $this->put('/super-admin/restaurant-approvals/1', [
            'decision' => 'approved',
        ])->assertNotFound();
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'approval-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }
}
