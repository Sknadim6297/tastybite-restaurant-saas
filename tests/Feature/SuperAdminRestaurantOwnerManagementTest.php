<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SuperAdminRestaurantOwnerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_owner_list_with_global_counts_search_filters_and_sorting(): void
    {
        $this->actingAsSuperAdmin();

        for ($index = 1; $index <= 18; $index++) {
            $restaurant = $this->createRestaurant("Cafe {$index}", "cafe-{$index}");
            $this->createOwner($restaurant, "owner{$index}@example.test", 'owner', $index % 2 === 0 ? 'active' : 'inactive');
        }

        $this->get(route('super-admin.restaurant-owners.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Restaurant Owners')
            ->assertSee('href="'.route('super-admin.restaurant-owners.index').'" class="sidebar-link is-active"', false)
            ->assertSeeInOrder(['<strong>18</strong>', '<strong>9</strong>', '<strong>9</strong>'], false);

        $this->get(route('super-admin.restaurant-owners.index', [
            'search' => 'Cafe 7',
            'restaurant_id' => Restaurant::where('slug', 'cafe-7')->value('id'),
            'status' => 'inactive',
            'sort' => 'restaurant',
            'direction' => 'asc',
        ]))->assertOk()
            ->assertSee('Cafe 7')
            ->assertDontSee('owner8@example.test');
    }

    public function test_super_admin_can_create_owner_for_existing_restaurant_and_duplicate_email_is_rejected(): void
    {
        config(['mail.default' => 'array']);
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Cedar House', 'cedar-house');

        $response = $this->post(route('super-admin.restaurant-owners.store'), [
            'restaurant_id' => $restaurant->id,
            'name' => 'Alicia Rivera',
            'email' => 'OWNER@EXAMPLE.TEST',
            'status' => 'active',
        ]);

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $response->assertRedirect(route('super-admin.restaurant-owners.show', $owner));
        $response->assertSessionHas('status');
        $this->assertSame($restaurant->id, $owner->restaurant_id);
        $this->assertSame('owner', $owner->role);
        $this->assertFalse(Hash::check('SecureOwnerPass123!', $owner->getRawOriginal('password')));
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'action' => 'owner_created',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);

        $this->post(route('super-admin.restaurant-owners.store'), [
            'restaurant_id' => $restaurant->id,
            'name' => 'Duplicate Owner',
            'email' => 'owner@example.test',
            'status' => 'active',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', 'owner@example.test')->count());
    }

    public function test_owner_profile_edit_cannot_change_role_or_restaurant_association(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('First Restaurant', 'first-restaurant');
        $otherRestaurant = $this->createRestaurant('Other Restaurant', 'other-restaurant');
        $owner = $this->createOwner($restaurant, 'owner@example.test');

        $this->put(route('super-admin.restaurant-owners.update', $owner), [
            'name' => 'Updated Owner',
            'email' => 'updated@example.test',
            'role' => 'super_admin',
            'restaurant_id' => $otherRestaurant->id,
            'status' => 'inactive',
        ])->assertRedirect(route('super-admin.restaurant-owners.show', $owner));

        $this->assertSame('Updated Owner', $owner->fresh()->name);
        $this->assertSame('updated@example.test', $owner->fresh()->email);
        $this->assertSame('owner', $owner->fresh()->role);
        $this->assertSame($restaurant->id, $owner->fresh()->restaurant_id);
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_owner_enable_disable_is_audited_and_disabled_account_cannot_log_in_or_keep_a_session(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Status House', 'status-house');
        $owner = $this->createOwner($restaurant, 'owner@example.test');

        $this->post(route('super-admin.restaurant-owners.status', $owner), ['status' => 'inactive'])
            ->assertRedirect(route('super-admin.restaurant-owners.show', $owner));
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'owner_disabled',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);

        Auth::guard('super_admin')->logout();
        Auth::forgetGuards();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => 'StrongOwnerPass123!',
        ])->assertSessionHasErrors('email');

        $owner->update(['status' => 'active']);
        Auth::forgetGuards();
        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $owner->update(['status' => 'inactive']);
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');

        Auth::forgetGuards();
        $this->actingAsSuperAdmin();
        $this->post(route('super-admin.restaurant-owners.status', $owner), ['status' => 'active'])
            ->assertRedirect(route('super-admin.restaurant-owners.show', $owner));
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'action' => 'owner_enabled',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);
    }

    public function test_owner_password_reset_uses_the_existing_single_use_broker_without_exposing_token(): void
    {
        config(['mail.default' => 'array']);
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Reset House', 'reset-house');
        $owner = $this->createOwner($restaurant, 'reset@example.test');

        $response = $this->post(route('super-admin.restaurant-owners.reset-password', $owner));

        $response->assertRedirect(route('super-admin.restaurant-owners.show', $owner));
        $response->assertSessionHas('status');
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $html = html_entity_decode($email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('~href="([^"]*restaurant/reset-password/[^"]+)"~', $html, $matches));
        $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $token = basename((string) parse_url($url, PHP_URL_PATH));
        $response->assertDontSee($token);

        $this->assertDatabaseHas('restaurant_audit_logs', [
            'action' => 'owner_password_reset_captured',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);
        $details = json_encode(RestaurantAuditLog::query()->pluck('details')->all());
        $this->assertStringNotContainsString($token, $details);
        $this->assertSame(60, config('auth.passwords.users.expire'));

        Auth::guard('super_admin')->logout();
        Auth::forgetGuards();
        $newPassword = 'ResetOwnerPassword4!';
        $this->post(route('restaurant.password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('restaurant.login'));

        $this->assertTrue(Hash::check($newPassword, $owner->fresh()->getRawOriginal('password')));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_staff_users_are_not_accessible_through_owner_specific_routes(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Staff House', 'staff-house');
        $staff = $this->createOwner($restaurant, 'staff@example.test', 'cashier');

        $this->get(route('super-admin.restaurant-owners.show', $staff))->assertNotFound();
        $this->get(route('super-admin.restaurant-owners.edit', $staff))->assertNotFound();
        $this->put(route('super-admin.restaurant-owners.update', $staff), [
            'name' => 'Escalated Staff',
            'email' => 'staff@example.test',
        ])->assertNotFound();
        $this->post(route('super-admin.restaurant-owners.status', $staff), ['status' => 'inactive'])
            ->assertNotFound();
        $this->post(route('super-admin.restaurant-owners.reset-password', $staff))->assertNotFound();
    }

    public function test_owner_details_show_restaurant_subscription_and_owner_audit_without_credentials(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Detail House', 'detail-house');
        $owner = $this->createOwner($restaurant, 'detail@example.test');
        RestaurantAuditLog::create([
            'actor_id' => $admin->id,
            'actor_type' => 'super_admin',
            'action' => 'owner_updated',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
            'details' => ['name' => $owner->name],
        ]);

        $this->get(route('super-admin.restaurant-owners.show', $owner))
            ->assertOk()
            ->assertSee($owner->email)
            ->assertSee($restaurant->name)
            ->assertSee('Owner activity and audit history')
            ->assertDontSee($owner->getRawOriginal('password'));
    }

    public function test_successful_owner_login_updates_last_login_and_adds_an_audit_event(): void
    {
        $restaurant = $this->createRestaurant('Login House', 'login-house');
        $owner = $this->createOwner($restaurant, 'login@example.test');

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => 'StrongOwnerPass123!',
        ])->assertRedirect(route('restaurant.dashboard'));

        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => null,
            'actor_type' => 'restaurant_owner',
            'action' => 'owner_login_success',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);
        $details = RestaurantAuditLog::query()
            ->where('target_id', $owner->id)
            ->where('action', 'owner_login_success')
            ->value('details');
        $this->assertSame([], $details);
    }

    public function test_restaurant_owner_cannot_access_super_admin_owner_management(): void
    {
        $restaurant = $this->createRestaurant('Tenant House', 'tenant-house');
        $owner = $this->createOwner($restaurant, 'tenant-owner@example.test');

        $this->actingAs($owner, 'web')
            ->get(route('super-admin.restaurant-owners.index'))
            ->assertRedirect(route('super-admin.login'));
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

    private function createOwner(Restaurant $restaurant, string $email, string $role = 'owner', string $status = 'active'): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Test Owner',
            'email' => $email,
            'password' => 'StrongOwnerPass123!',
            'role' => $role,
            'status' => $status,
        ]);
    }
}
