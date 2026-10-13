<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantApprovalHistory;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RestaurantApprovalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_pending_restaurant_and_review_it(): void
    {
        config(['mail.default' => 'array']);
        Notification::fake();
        $admin = $this->actingAsSuperAdmin();

        $this->post(route('super-admin.restaurants.store'), [
            'restaurant' => [
                'name' => 'Review House',
                'slug' => 'review-house',
                'email' => 'hello@review-house.test',
                'status' => 'active',
                'approval_status' => 'pending',
            ],
            'owner' => ['name' => 'Review Owner', 'email' => 'owner@review-house.test'],
        ])->assertSessionHas('status');

        $restaurant = Restaurant::query()->where('slug', 'review-house')->firstOrFail();
        $this->assertSame('pending', $restaurant->approval_status);
        $this->assertDatabaseHas('restaurant_approval_history', [
            'restaurant_id' => $restaurant->id,
            'actor_id' => $admin->id,
            'previous_status' => null,
            'new_status' => 'pending',
        ]);

        $this->get(route('super-admin.restaurant-approvals.index'))
            ->assertOk()
            ->assertSee('Review House')
            ->assertSee('Pending');

        $this->get(route('super-admin.restaurant-approvals.show', $restaurant))
            ->assertOk()
            ->assertSee('Submitted documents')
            ->assertSee('does not collect verification documents');
    }

    public function test_approval_is_audited_and_cannot_be_applied_twice(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Decision House', 'decision-house', 'pending');

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'approved',
            'reason' => 'Business details reviewed.',
        ])->assertRedirect(route('super-admin.restaurant-approvals.show', $restaurant));

        $this->assertSame('approved', $restaurant->fresh()->approval_status);
        $this->assertDatabaseHas('restaurant_approval_history', [
            'restaurant_id' => $restaurant->id,
            'actor_id' => $admin->id,
            'previous_status' => 'pending',
            'new_status' => 'approved',
            'reason' => 'Business details reviewed.',
        ]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'restaurant_approval_approved',
            'target_type' => 'restaurant',
            'target_id' => $restaurant->id,
        ]);

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'approved',
        ])->assertSessionHasErrors('decision');

        $this->assertSame(1, RestaurantApprovalHistory::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('new_status', 'approved')
            ->count());
    }

    public function test_information_request_is_visible_to_owner_and_operational_routes_stay_blocked(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Pending House', 'pending-house', 'pending');
        $owner = $this->createOwner($restaurant);

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'information_requested',
            'reason' => 'Please provide a current business address.',
        ])->assertRedirect();

        $this->actingAs($owner, 'web')
            ->withSession(['restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword())])
            ->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee('More information is required')
            ->assertSee('Please provide a current business address.')
            ->assertSee(route('restaurant.support.index'));

        $this->get('/pos')->assertRedirect(route('restaurant.dashboard'));
        $this->assertSame('information_requested', $restaurant->fresh()->approval_status);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'restaurant_approval_information_requested',
            'target_id' => $restaurant->id,
        ]);
    }

    public function test_rejected_approval_requires_a_reason_and_remains_terminal(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Reject House', 'reject-house', 'pending');

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'rejected',
        ])->assertSessionHasErrors('reason');

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'rejected',
            'reason' => 'Required operating details are missing.',
        ])->assertRedirect();

        $this->put(route('super-admin.restaurant-approvals.decide', $restaurant), [
            'decision' => 'approved',
        ])->assertSessionHasErrors('decision');
        $this->assertSame('rejected', $restaurant->fresh()->approval_status);
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createRestaurant(string $name, string $slug, string $approvalStatus): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "{$slug}@example.test",
            'status' => 'active',
            'approval_status' => $approvalStatus,
        ]);
    }

    private function createOwner(Restaurant $restaurant): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Restaurant Owner',
            'email' => 'owner-'.$restaurant->slug.'@example.test',
            'password' => 'StrongOwnerPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }
}
