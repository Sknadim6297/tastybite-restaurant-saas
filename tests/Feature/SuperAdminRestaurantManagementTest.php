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
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SuperAdminRestaurantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_the_restaurant_list(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        $this->get(route('super-admin.restaurants.index'))
            ->assertOk()
            ->assertSee('Restaurants')
            ->assertSee($admin->name);
    }

    public function test_super_admin_can_create_a_restaurant_and_initial_owner(): void
    {
        config(['mail.default' => 'array']);
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        $response = $this->post(route('super-admin.restaurants.store'), [
            'restaurant' => [
                'name' => 'Cedar House',
                'slug' => 'cedar-house',
                'email' => 'hello@cedarhouse.test',
                'phone' => '+15555550001',
                'address' => '14 Market Street',
                'status' => 'active',
            ],
            'owner' => [
                'name' => 'Alicia Rivera',
                'email' => ' OWNER@CedarHouse.TEST ',
            ],
        ]);

        $response->assertSessionHas('status');
        $restaurant = Restaurant::firstOrFail();
        $response->assertRedirect(route('super-admin.restaurants.show', $restaurant));
        $owner = User::firstOrFail();

        $this->assertSame('cedar-house', $restaurant->slug);
        $this->assertSame('owner@cedarhouse.test', $owner->email);
        $this->assertSame('owner', $owner->role);
        $this->assertSame($restaurant->id, $owner->restaurant_id);
        $this->assertSame(1, $restaurant->owners()->count());
        $this->assertNotSame('', $owner->getRawOriginal('password'));
        $this->assertNotSame('SecureOwnerPass123!', $owner->getRawOriginal('password'));
        $this->assertDatabaseHas('restaurants', ['email' => 'hello@cedarhouse.test']);
        $this->assertDatabaseHas('users', ['restaurant_id' => $restaurant->id, 'role' => 'owner']);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'restaurant_created',
            'target_id' => $restaurant->id,
        ]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'owner_setup_link_sent',
            'target_id' => $owner->id,
        ]);

        $auditDetails = json_encode(RestaurantAuditLog::query()->pluck('details')->all());
        $this->assertStringNotContainsString($owner->getRawOriginal('password'), $auditDetails);
        $this->assertStringNotContainsString('token', strtolower($auditDetails));

        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('Reset your TastyBite Restaurant Admin password', $email->getSubject());

        $html = html_entity_decode($email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('~href="([^"]*restaurant/reset-password/[^"]+)"~', $html, $matches));
        $setupUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $token = basename((string) parse_url($setupUrl, PHP_URL_PATH));
        parse_str((string) parse_url($setupUrl, PHP_URL_QUERY), $query);
        $this->assertSame($owner->email, $query['email'] ?? null);
        $response->assertDontSee($token);

        Auth::guard('super_admin')->logout();
        Auth::forgetGuards();

        $newPassword = 'FreshOwnerPassword3!';
        $this->post(route('restaurant.password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check($newPassword, $owner->fresh()->getRawOriginal('password')));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->post(route('restaurant.password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'AnotherOwnerPassword4!',
            'password_confirmation' => 'AnotherOwnerPassword4!',
        ])->assertSessionHasErrors(['email']);
    }

    public function test_restaurant_owner_cannot_access_super_admin_restaurant_management_routes(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Harbor Table',
            'slug' => 'harbor-table',
            'email' => 'hello@harbortable.test',
            'status' => 'active',
        ]);

        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Harbor Owner',
            'email' => 'owner@harbortable.test',
            'password' => 'SecureOwnerPass123!',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'web')
            ->get(route('super-admin.restaurants.index'))
            ->assertRedirect(route('super-admin.login'));
    }

    public function test_owner_management_is_scoped_to_the_url_restaurant_and_owner_role(): void
    {
        $this->actingAsSuperAdmin();
        $restaurantA = $this->createRestaurant('Restaurant A', 'restaurant-a');
        $restaurantB = $this->createRestaurant('Restaurant B', 'restaurant-b');
        $ownerA = $this->createUser($restaurantA, 'owner-a@example.test', 'owner');
        $staffB = $this->createUser($restaurantB, 'staff-b@example.test', 'cashier');

        $this->post(route('super-admin.restaurants.owners.toggle-status', [$restaurantB, $ownerA]), [
            'status' => 'inactive',
        ])->assertNotFound();

        $this->post(route('super-admin.restaurants.owners.reset-password', [$restaurantB, $ownerA]))
            ->assertNotFound();

        $this->post(route('super-admin.restaurants.owners.toggle-status', [$restaurantB, $staffB]), [
            'status' => 'inactive',
        ])->assertNotFound();

        $this->assertSame('active', $ownerA->fresh()->status);
        $this->assertSame('active', $staffB->fresh()->status);
        $this->assertDatabaseMissing('restaurant_audit_logs', [
            'target_type' => 'restaurant_user',
            'target_id' => $ownerA->id,
        ]);
    }

    public function test_initial_setup_link_uses_the_single_use_expiring_password_broker_without_disclosing_the_token(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Setup House', 'setup-house');
        $owner = $this->createUser($restaurant, 'setup@example.test', 'owner');

        $response = $this->post(route('super-admin.restaurants.owners.reset-password', [$restaurant, $owner]));

        $response->assertRedirect(route('super-admin.restaurants.show', $restaurant));
        $response->assertSessionHas('status');
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $html = html_entity_decode($email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('~href="([^"]*restaurant/reset-password/[^"]+)"~', $html, $matches));
        $setupUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $token = basename((string) parse_url($setupUrl, PHP_URL_PATH));

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $owner->email,
        ]);
        $this->assertSame(60, config('auth.passwords.users.expire'));

        $this->travel(61)->minutes();
        $this->post(route('restaurant.password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'ExpiredOwnerPassword3!',
            'password_confirmation' => 'ExpiredOwnerPassword3!',
        ])->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('StrongTestPass123!', $owner->fresh()->getRawOriginal('password')));
    }

    public function test_restaurant_creation_rejects_duplicate_owner_email_without_creating_another_restaurant(): void
    {
        $this->actingAsSuperAdmin();
        $existingRestaurant = $this->createRestaurant('Existing House', 'existing-house');
        $this->createUser($existingRestaurant, 'existing-owner@example.test', 'owner');

        $this->post(route('super-admin.restaurants.store'), [
            'restaurant' => [
                'name' => 'Duplicate Owner House',
                'slug' => 'duplicate-owner-house',
                'email' => 'hello@duplicate-owner-house.test',
                'status' => 'active',
            ],
            'owner' => [
                'name' => 'Duplicate Email Owner',
                'email' => ' EXISTING-OWNER@EXAMPLE.TEST ',
            ],
        ])->assertSessionHasErrors('owner.email');

        $this->assertDatabaseMissing('restaurants', ['slug' => 'duplicate-owner-house']);
        $this->assertSame(1, User::query()->where('email', 'existing-owner@example.test')->count());
        $this->assertSame(1, Restaurant::query()->count());
    }

    public function test_log_mail_transport_is_reported_as_unavailable_without_attempting_to_log_setup_tokens(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);
        $this->actingAsSuperAdmin();

        $response = $this->post(route('super-admin.restaurants.store'), [
            'restaurant' => [
                'name' => 'Local House',
                'slug' => 'local-house',
                'email' => 'hello@localhouse.test',
                'status' => 'active',
            ],
            'owner' => [
                'name' => 'Local Owner',
                'email' => 'owner@localhouse.test',
            ],
        ]);

        $response->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'MAIL_MAILER'));
        $restaurant = Restaurant::query()->where('slug', 'local-house')->firstOrFail();
        $response->assertRedirect(route('super-admin.restaurants.show', $restaurant));
        $response->assertSessionHas('toast_type', 'warning');
        Notification::assertNothingSent();
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'action' => 'owner_setup_link_unavailable',
            'target_type' => 'restaurant_user',
        ]);
    }

    public function test_restaurant_list_statistics_cover_all_pages_and_filters_search_and_sort_are_applied(): void
    {
        $this->actingAsSuperAdmin();
        for ($index = 1; $index <= 18; $index++) {
            $status = match ($index % 3) {
                0 => 'active',
                1 => 'suspended',
                default => 'inactive',
            };
            $this->createRestaurant("Cafe {$index}", "cafe-{$index}", $status);
        }

        $this->get(route('super-admin.restaurants.index', ['page' => 2]))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>18</strong>',
                '<strong>6</strong>',
                '<strong>6</strong>',
                '<strong>6</strong>',
            ], false);

        $this->get(route('super-admin.restaurants.index', [
            'search' => 'Cafe 7',
            'status' => 'suspended',
            'sort' => 'name',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertSee('Cafe 7')
            ->assertDontSee('Cafe 8');
    }

    public function test_restaurant_status_and_owner_changes_are_audited_with_actor_and_status_only(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant('Audit House', 'audit-house');
        $owner = $this->createUser($restaurant, 'audit-owner@example.test', 'owner');

        $this->post(route('super-admin.restaurants.toggle-status', $restaurant), [
            'status' => 'suspended',
        ])->assertRedirect(route('super-admin.restaurants.show', $restaurant));

        $this->post(route('super-admin.restaurants.owners.toggle-status', [$restaurant, $owner]), [
            'status' => 'inactive',
        ])->assertRedirect(route('super-admin.restaurants.show', $restaurant));

        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'restaurant_suspended',
            'target_type' => 'restaurant',
            'target_id' => $restaurant->id,
        ]);
        $this->assertDatabaseHas('restaurant_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'owner_disabled',
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
        ]);
        $this->assertSame('suspended', $restaurant->fresh()->status);
        $this->assertSame('inactive', $owner->fresh()->status);

        $this->get(route('super-admin.restaurants.show', $restaurant))
            ->assertOk()
            ->assertSee($admin->name)
            ->assertSee('data-confirm=', false);
    }

    public function test_restaurant_provisioning_rolls_back_when_owner_creation_fails(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            'eloquent.creating: '.User::class,
            static function (): void {
                throw new \RuntimeException('Simulated owner insert failure.');
            },
        );

        try {
            app(\App\Services\RestaurantProvisioningService::class)->provision(
                [
                    'name' => 'Rollback House',
                    'slug' => 'rollback-house',
                    'email' => 'hello@rollback.test',
                ],
                [
                    'name' => 'Rollback Owner',
                    'email' => 'owner@rollback.test',
                ],
            );
            $this->fail('The simulated owner creation failure should be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated owner insert failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('restaurants', ['slug' => 'rollback-house']);
        $this->assertDatabaseMissing('users', ['email' => 'owner@rollback.test']);
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createRestaurant(string $name, string $slug, string $status = 'active'): Restaurant
    {
        return Restaurant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "{$slug}@example.test",
            'status' => $status,
        ]);
    }

    private function createUser(Restaurant $restaurant, string $email, string $role): User
    {
        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'StrongTestPass123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
