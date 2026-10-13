<?php

namespace Tests\Feature;

use App\Models\PlatformAnnouncement;
use App\Models\Restaurant;
use App\Models\RestaurantAnnouncementRead;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_publish_all_audience_announcement_and_owner_can_mark_it_read(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Announcement House', 'announcement-house');
        $owner = $this->createOwner($restaurant);

        $this->post(route('super-admin.announcements.store'), [
            'title' => 'Planned maintenance',
            'body' => 'The platform will be unavailable for scheduled updates.',
            'audience' => 'all',
        ])->assertRedirect();

        $announcement = PlatformAnnouncement::query()->firstOrFail();
        $this->assertSame('draft', $announcement->status);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'target_type' => 'announcement',
            'action' => 'announcement_draft_created',
        ]);

        $this->post(route('super-admin.announcements.publish', $announcement))
            ->assertRedirect()
            ->assertSessionHas('status', 'Announcement published to the selected in-app audience. No email was sent.');

        $this->assertSame('published', $announcement->fresh()->status);
        $this->actingAs($owner, 'web')
            ->withSession(['restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword())])
            ->get(route('restaurant.announcements.index'))
            ->assertOk()
            ->assertSee('Planned maintenance')
            ->assertSee('New');

        $this->post(route('restaurant.announcements.read', $announcement))
            ->assertRedirect(route('restaurant.announcements.show', $announcement));
        $this->assertDatabaseHas('restaurant_announcement_reads', [
            'platform_announcement_id' => $announcement->id,
            'restaurant_id' => $restaurant->id,
        ]);
        $this->assertSame(1, RestaurantAnnouncementRead::query()
            ->where('platform_announcement_id', $announcement->id)->count());
    }

    public function test_selected_audience_does_not_leak_to_other_restaurants(): void
    {
        $this->actingAsSuperAdmin();
        $restaurantA = $this->createRestaurant('Selected House', 'selected-house');
        $restaurantB = $this->createRestaurant('Other House', 'other-house');
        $ownerA = $this->createOwner($restaurantA);
        $ownerB = $this->createOwner($restaurantB);

        $this->post(route('super-admin.announcements.store'), [
            'title' => 'Selected notice',
            'body' => 'This notice is only for one selected restaurant.',
            'audience' => 'selected',
            'restaurant_ids' => [$restaurantA->id],
        ])->assertRedirect();

        $announcement = PlatformAnnouncement::query()->firstOrFail();
        $this->post(route('super-admin.announcements.publish', $announcement))->assertRedirect();

        $this->actingAs($ownerA, 'web')
            ->withSession(['restaurant_user_password_version' => hash('sha256', $ownerA->getAuthPassword())])
            ->get(route('restaurant.announcements.show', $announcement))
            ->assertOk()
            ->assertSee('Selected notice');

        $this->actingAs($ownerB, 'web')
            ->withSession(['restaurant_user_password_version' => hash('sha256', $ownerB->getAuthPassword())])
            ->get(route('restaurant.announcements.show', $announcement))
            ->assertNotFound();
    }

    public function test_expired_or_archived_announcement_is_not_visible_to_restaurant_owner(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Expiry House', 'expiry-house');
        $owner = $this->createOwner($restaurant);
        $announcement = PlatformAnnouncement::create([
            'created_by' => $admin->id,
            'title' => 'Expired notice',
            'body' => 'This message is no longer available.',
            'audience' => 'all',
            'status' => 'published',
            'published_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($owner, 'web')
            ->withSession(['restaurant_user_password_version' => hash('sha256', $owner->getAuthPassword())])
            ->get(route('restaurant.announcements.show', $announcement))
            ->assertNotFound();

        $this->actingAsSuperAdmin();
        $this->post(route('super-admin.announcements.archive', $announcement))->assertRedirect();
        $this->assertSame('archived', $announcement->fresh()->status);
    }

    public function test_selected_audience_requires_at_least_one_existing_restaurant(): void
    {
        $this->actingAsSuperAdmin();
        $this->from(route('super-admin.announcements.create'))
            ->post(route('super-admin.announcements.store'), [
                'title' => 'Selected notice',
                'body' => 'This selected audience must have a restaurant target.',
                'audience' => 'selected',
                'restaurant_ids' => [],
            ])
            ->assertRedirect(route('super-admin.announcements.create'))
            ->assertSessionHasErrors('restaurant_ids');

        $this->assertDatabaseCount('platform_announcements', 0);
    }

    public function test_publishing_is_blocked_when_audience_has_no_active_owner_recipients(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('No Owner House', 'no-owner-house');

        $this->post(route('super-admin.announcements.store'), [
            'title' => 'No recipient notice',
            'body' => 'This notice must have at least one active owner.',
            'audience' => 'selected',
            'restaurant_ids' => [$restaurant->id],
        ])->assertRedirect();

        $announcement = PlatformAnnouncement::query()->firstOrFail();
        $this->from(route('super-admin.announcements.show', $announcement))
            ->post(route('super-admin.announcements.publish', $announcement))
            ->assertRedirect(route('super-admin.announcements.show', $announcement))
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $announcement->fresh()->status);
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

    private function createRestaurant(string $name, string $slug): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "{$slug}@example.test",
            'status' => 'active',
        ]);
    }

    private function createOwner(Restaurant $restaurant): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner',
            'email' => 'owner-'.$restaurant->slug.'@example.test',
            'password' => 'StrongOwnerPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }
}
