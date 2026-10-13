<?php

namespace Tests\Feature;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_super_admin_login_is_audited_without_storing_credentials(): void
    {
        $email = 'unknown-'.Str::uuid().'@example.test';
        $password = 'NeverStoreThisPassword123!';

        $this->post(route('super-admin.login.submit'), [
            'email' => $email,
            'password' => $password,
        ])->assertRedirect(route('super-admin.login'))
            ->assertSessionHasErrors('email');

        $event = RestaurantAuditLog::query()->where('action', 'super_admin_login_failed')->firstOrFail();
        $this->assertSame('super_admin', $event->target_type);
        $this->assertNull($event->target_id);
        $this->assertArrayHasKey('account_fingerprint', $event->details);
        $this->assertStringNotContainsString($email, json_encode($event->details));
        $this->assertStringNotContainsString($password, json_encode($event->details));
        $this->assertSame(
            hash_hmac('sha256', Str::lower($email), (string) config('app.key')),
            $event->details['account_fingerprint'],
        );
    }

    public function test_security_center_is_super_admin_only_and_reports_recorded_authentication_events(): void
    {
        $this->get(route('super-admin.security-center.index'))
            ->assertRedirect(route('super-admin.login'));

        $admin = SuperAdmin::create([
            'name' => 'Security Admin',
            'email' => 'security-admin@example.test',
            'password' => 'StrongAdminPass1!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin')
            ->withSession(['super_admin_password_version' => hash('sha256', $admin->getAuthPassword())])
            ->get(route('super-admin.security-center.index'))
            ->assertOk()
            ->assertSee('Security Center')
            ->assertSee('Failed login attempts')
            ->assertSee('Password reset token expiry')
            ->assertSee('Security Admin');
    }

    public function test_restaurant_login_failures_are_audited_without_persisting_the_submitted_email(): void
    {
        $email = 'restaurant-'.Str::uuid().'@example.test';
        $this->post(route('restaurant.login.submit'), [
            'email' => $email,
            'password' => 'NeverStoreThisPassword123!',
        ])->assertRedirect(route('restaurant.login'));

        $event = RestaurantAuditLog::query()->where('action', 'restaurant_login_failed')->firstOrFail();
        $this->assertSame('restaurant_user', $event->target_type);
        $this->assertStringNotContainsString($email, json_encode($event->details));
    }
}
