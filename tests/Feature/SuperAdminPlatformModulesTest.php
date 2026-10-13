<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\SuperAdminNotification;
use App\Services\PlatformSettingsService;
use App\Services\SuperAdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuperAdminPlatformModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_report_requires_super_admin_and_uses_exact_decimal_totals(): void
    {
        $this->get(route('super-admin.reports.index'))->assertRedirect(route('super-admin.login'));
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();

        $first = $this->createPayment($admin, $restaurant, $plan, '99999999.99', 'approved', 'REPORT-1');
        $second = $this->createPayment($admin, $restaurant, $plan, '99999999.99', 'approved', 'REPORT-2');
        $demoRestaurant = $this->createRestaurant('demo-tb-2026-report');
        $this->createPayment($admin, $demoRestaurant, $plan, '99999999.99', 'approved', 'REPORT-DEMO', 'DEMO DATA ONLY - not actual money');
        $pending = $this->createPayment($admin, $restaurant, $plan, '10.00', 'pending', 'REPORT-PENDING');
        SubscriptionPaymentRefund::create([
            'subscription_payment_id' => $first->id,
            'admin_id' => $admin->id,
            'amount' => '100.01',
            'method' => 'upi',
            'reference' => 'REPORT-REFUND',
            'refunded_at' => now(),
            'reason' => 'Verified manual refund record.',
        ]);

        $this->get(route('super-admin.reports.index'))
            ->assertOk()
            ->assertSee('INR 199999899.97')
            ->assertDontSee('INR 299999899.97');
        $this->get(route('super-admin.reports.payments', ['scope' => 'demo']))
            ->assertOk()
            ->assertSee('REPORT-DEMO')
            ->assertDontSee('REPORT-1');
        $export = $this->get(route('super-admin.reports.export', ['type' => 'revenue', 'scope' => 'real']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('REPORT-1', $export->streamedContent());
        $this->assertStringNotContainsString('REPORT-PENDING', $export->streamedContent());
        $this->assertStringNotContainsString('REPORT-DEMO', $export->streamedContent());
        $this->assertDatabaseHas('subscription_payments', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertSame('99999999.99', $second->amount);
    }

    public function test_business_notifications_are_idempotent_per_administrator_and_event(): void
    {
        $this->actingAsSuperAdmin();
        $service = app(SuperAdminNotificationService::class);

        $service->businessEvent('restaurant_registered', 'restaurant-created:77', 'New restaurant', 'Restaurant was registered.');
        $service->businessEvent('restaurant_registered', 'restaurant-created:77', 'New restaurant', 'Restaurant was registered.');

        $this->assertDatabaseCount('super_admin_notifications', 1);
        $notification = SuperAdminNotification::query()->firstOrFail();
        $this->assertNull($notification->read_at);
        $this->get(route('super-admin.notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'New restaurant');

        $this->post(route('super-admin.notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->post(route('super-admin.notifications.read-all'))->assertRedirect();
    }

    public function test_expiry_alert_command_is_safe_to_repeat(): void
    {
        $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $subscription = RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'price' => $plan->price,
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('super-admin:notify-subscription-expiry')->assertExitCode(0);
        $this->artisan('super-admin:notify-subscription-expiry')->assertExitCode(0);

        $this->assertDatabaseCount('super_admin_notifications', 1);
        $this->assertDatabaseHas('super_admin_notifications', [
            'event_key' => 'subscription-expired:'.$subscription->id.':'.$subscription->expires_at->timestamp,
            'category' => 'subscription_expired',
        ]);
    }

    public function test_all_new_module_pages_render_without_records(): void
    {
        $this->actingAsSuperAdmin();

        foreach ([
            route('super-admin.reports.restaurants'),
            route('super-admin.reports.subscriptions'),
            route('super-admin.reports.payments'),
            route('super-admin.reports.revenue'),
            route('super-admin.notifications.index'),
            route('super-admin.activity-logs.index'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        foreach (['general', 'subscription', 'payment', 'notifications', 'branding', 'security'] as $tab) {
            $this->get(route('super-admin.settings.index', ['tab' => $tab]))->assertOk();
        }
    }

    public function test_sidebar_brand_keeps_default_two_tone_and_custom_name_and_logo_rendering(): void
    {
        $this->actingAsSuperAdmin();
        $dashboard = $this->get(route('super-admin.dashboard'))->assertOk();
        $dashboard->assertSee('<span class="brand-name">Tasty<span>Bite</span></span>', false);

        app(PlatformSettingsService::class)->saveGroup('general', [
            'platform_name' => 'TastyBite Plus',
            'support_email' => '',
            'support_phone' => '',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'date_format' => 'M j, Y',
        ], SuperAdmin::query()->firstOrFail());
        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('<span class="brand-name">TastyBite Plus</span>', false)
            ->assertDontSee('<span class="brand-name">Tasty<span>Bite</span></span>', false);
    }

    public function test_activity_log_is_read_only_searchable_and_redacts_secrets(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $restaurant = $this->createRestaurant();
        RestaurantAuditLog::create([
            'actor_id' => $admin->id,
            'actor_type' => 'super_admin',
            'restaurant_id' => $restaurant->id,
            'action' => 'test_sensitive_action',
            'target_type' => 'restaurant',
            'target_id' => $restaurant->id,
            'ip_address' => '198.51.100.8',
            'details' => ['password' => 'MustNeverAppear123!', 'restaurant_id' => $restaurant->id],
        ]);

        $this->get(route('super-admin.activity-logs.index', ['search' => 'test_sensitive_action']))
            ->assertOk()
            ->assertSee($restaurant->name)
            ->assertSee('198.51.100.8');
        $log = RestaurantAuditLog::query()->where('action', 'test_sensitive_action')->firstOrFail();
        $this->get(route('super-admin.activity-logs.show', $log))
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertDontSee('MustNeverAppear123!');
        $export = $this->get(route('super-admin.activity-logs.export', ['restaurant_id' => $restaurant->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('198.51.100.8', $export->streamedContent());
    }

    public function test_settings_persist_preferences_enforce_manual_payment_policy_and_password_security(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $this->post(route('super-admin.settings.update', 'general'), [
            'platform_name' => 'TastyBite QA',
            'support_email' => 'support@example.test',
            'support_phone' => '',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'date_format' => 'Y-m-d',
        ])->assertRedirect(route('super-admin.settings.index', ['tab' => 'general']));
        $this->assertSame('TastyBite QA', app(PlatformSettingsService::class)->group('general')['platform_name']);
        $this->assertDatabaseHas('restaurant_audit_logs', ['action' => 'platform_settings_updated', 'actor_id' => $admin->id]);

        $restaurant = $this->createRestaurant();
        $plan = $this->createPlan();
        $this->post(route('super-admin.settings.update', 'payment'), [
            'currency' => 'INR',
            'allowed_methods' => [],
            'instructions' => '',
        ])->assertRedirect();
        $this->get(route('super-admin.payments.create'))->assertForbidden();
        $this->post(route('super-admin.payments.store'), [
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
        ])->assertForbidden();

        $this->put(route('super-admin.settings.security.password'), [
            'current_password' => 'StrongAdminPass123!',
            'password' => 'ReplacementAdminPass456!',
            'password_confirmation' => 'ReplacementAdminPass456!',
        ])->assertRedirect(route('super-admin.settings.index', ['tab' => 'security']));
        $this->assertNotSame('ReplacementAdminPass456!', $admin->fresh()->getRawOriginal('password'));
        $this->assertAuthenticatedAs($admin->fresh(), 'super_admin');
        $this->get(route('super-admin.dashboard'))->assertOk();
    }

    public function test_brand_assets_are_validated_and_served_only_inside_super_admin_routes(): void
    {
        Storage::fake('local');
        $this->actingAsSuperAdmin();
        $this->post(route('super-admin.settings.branding'), [
            'logo' => UploadedFile::fake()->image('brand.png', 150, 60),
        ])->assertRedirect();

        $this->assertDatabaseHas('platform_settings', ['key' => 'general.logo_path']);
        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('class="platform-logo"', false)
            ->assertDontSee('<span class="brand-name">Tasty<span>Bite</span></span>', false);
        $this->get(route('super-admin.settings.branding.show', 'logo'))->assertOk();
        $this->post(route('super-admin.logout'));
        $this->get(route('super-admin.settings.branding.show', 'logo'))->assertRedirect(route('super-admin.login'));
    }

    private function actingAsSuperAdmin(): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Platform Test Admin',
            'email' => 'platform-admin@example.test',
            'password' => 'StrongAdminPass123!',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));

        return $admin;
    }

    private function createRestaurant(string $slug = 'platform-report-restaurant'): Restaurant
    {
        return Restaurant::create([
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'status' => 'active',
        ]);
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Report Plan',
            'code' => 'REPORT-PLAN',
            'price' => '99999999.99',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
        ]);
    }

    private function createPayment(
        SuperAdmin $admin,
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        string $amount,
        string $status,
        string $reference,
        ?string $notes = null,
    ): SubscriptionPayment {
        return SubscriptionPayment::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'created_by' => $admin->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->code,
            'amount' => $amount,
            'currency' => 'INR',
            'payment_method' => 'upi',
            'transaction_reference' => $reference,
            'status' => $status,
            'paid_at' => now(),
            'notes' => $notes,
        ]);
    }
}
