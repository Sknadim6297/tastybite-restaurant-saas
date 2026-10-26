<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\OwnerPasswordSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class RestaurantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StrongRestaurantPass1!';

    public function test_guest_can_view_the_restaurant_login_page(): void
    {
        $response = $this->get(route('restaurant.login'));
        $response
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('assets/app-font.css', false)
            ->assertSee('fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(1, substr_count($response->getContent(), 'fonts.googleapis.com/css2?family=Inter'));
    }

    public function test_homepage_redirects_guests_to_the_existing_restaurant_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('restaurant.login'));

        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');
    }

    public function test_homepage_redirects_an_authenticated_active_owner_to_the_dashboard(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put(
            'restaurant_user_password_version',
            hash('sha256', $owner->getAuthPassword()),
        );

        $this->get('/')
            ->assertRedirect(route('restaurant.dashboard'));
        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee($restaurant->name);
    }

    public function test_guest_pos_request_redirects_to_login_without_claiming_the_session_expired(): void
    {
        $this->get(route('pos.index'))
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHasNoErrors();
    }

    public function test_invalid_restaurant_credentials_receive_a_generic_error(): void
    {
        $this->post(route('restaurant.login.submit'), [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHasErrors(['email'])
            ->assertSessionHas('_old_input.email', 'unknown@example.com')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_valid_restaurant_credentials_regenerate_the_session_and_open_the_dashboard(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->get(route('restaurant.login'));
        $previousSessionId = $this->app['session']->getId();

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])
            ->assertRedirect(route('restaurant.dashboard'))
            ->assertSessionHas('status');

        $this->assertNotSame($previousSessionId, $this->app['session']->getId());
        $this->assertAuthenticatedAs($owner, 'web');

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee($restaurant->name);
    }

    public function test_restaurant_owner_password_is_hashed_at_rest(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->assertNotSame(self::PASSWORD, $owner->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->getRawOriginal('password')));
    }

    public function test_owner_can_request_reset_set_password_once_and_log_in_using_the_local_reset_url(): void
    {
        config(['mail.default' => 'array']);
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->post(route('restaurant.password.email'), ['email' => strtoupper($owner->email)])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHasErrors([
                'email' => 'Unable to send reset email. Please try again later.',
            ])
            ->assertSessionMissing('status');

        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $html = html_entity_decode($email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('~href="([^"]*restaurant/reset-password/[^"]+)"~', $html, $matches));
        $resetUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($resetUrl, PHP_URL_HOST));
        $this->assertSame('/restaurant/reset-password/'.basename((string) parse_url($resetUrl, PHP_URL_PATH)), parse_url($resetUrl, PHP_URL_PATH));
        $token = basename((string) parse_url($resetUrl, PHP_URL_PATH));
        $this->assertSame(60, config('auth.passwords.users.expire'));

        $newPassword = 'ResetOwnerPassword7!';
        $resetData = [
            'token' => $token,
            'email' => $owner->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ];
        $this->post(route('restaurant.password.update'), $resetData)
            ->assertRedirect(route('restaurant.login'));

        $this->post(route('restaurant.password.update'), $resetData)
            ->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => $newPassword,
        ])->assertRedirect(route('restaurant.dashboard'));
        $this->assertAuthenticatedAs($owner->fresh(), 'web');
    }

    public function test_unregistered_owner_reset_email_uses_a_generic_response_by_default(): void
    {
        config(['auth.passwords.users.expose_missing_accounts' => false]);

        $this->post(route('restaurant.password.email'), ['email' => 'missing@example.test'])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHas('status', 'If an active restaurant owner account matches this email, reset instructions will be sent.')
            ->assertSessionHas('toast_type', 'information');

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_unregistered_owner_reset_email_can_show_opt_in_validation_message(): void
    {
        config(['auth.passwords.users.expose_missing_accounts' => true]);

        $this->from(route('restaurant.password.request'))
            ->post(route('restaurant.password.email'), ['email' => 'missing@example.test'])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHasErrors([
                'email' => 'No account found with this email address.',
            ])
            ->assertSessionMissing('status');

        $this->get(route('restaurant.password.request'))
            ->assertSee('id="email-error"', false)
            ->assertSee('No account found with this email address.')
            ->assertDontSee('If an active restaurant owner account matches this email');

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_owner_reset_email_format_is_reported_below_the_input(): void
    {
        $this->from(route('restaurant.password.request'))
            ->post(route('restaurant.password.email'), ['email' => 'not-an-email'])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHasErrors(['email']);

        $this->get(route('restaurant.password.request'))
            ->assertSee('id="email-error"', false)
            ->assertSee('The email field must be a valid email address.');
    }

    public function test_owner_reset_success_message_requires_service_transport_acceptance(): void
    {
        $this->mock(OwnerPasswordSetupService::class, function ($mock): void {
            $mock->shouldReceive('sendForEmail')
                ->once()
                ->with('owner@example.test')
                ->andReturn([
                    'sent' => true,
                    'result' => 'accepted_by_transport',
                    'message' => 'accepted',
                    'toast' => 'success',
                ]);
        });

        $this->post(route('restaurant.password.email'), ['email' => ' OWNER@example.test '])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHas('status', 'Password reset link has been sent successfully.')
            ->assertSessionHas('toast_type', 'success');
    }

    public function test_public_reset_response_is_generic_when_failover_contains_a_log_mailer(): void
    {
        $owner = User::create([
            'restaurant_id' => Restaurant::create([
                'name' => 'Bella Vista',
                'slug' => 'bella-vista',
                'email' => 'hello@bellavista.test',
                'status' => 'active',
            ])->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'log'],
            ],
        ]);
        Notification::fake();

        $this->post(route('restaurant.password.email'), ['email' => $owner->email])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHasErrors([
                'email' => 'Unable to send reset email. Please try again later.',
            ])
            ->assertSessionMissing('status');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        Notification::assertNothingSent();
    }

    public function test_restaurant_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('restaurant.login.submit'), [
                'email' => 'unknown@example.com',
                'password' => self::PASSWORD,
            ])->assertRedirect(route('restaurant.login'));
        }

        $this->post(route('restaurant.login.submit'), [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ])->assertTooManyRequests();
    }

    public function test_restaurant_password_reset_requests_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('restaurant.password.email'), [
                'email' => 'unknown@example.test',
            ])->assertRedirect(route('restaurant.password.request'));
        }

        $this->post(route('restaurant.password.email'), [
            'email' => 'unknown@example.test',
        ])->assertTooManyRequests();
    }

    public function test_restaurant_logout_clears_authentication_and_invalidates_the_session(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $previousCsrfToken = $this->app['session']->token();

        $this->post(route('restaurant.logout'))
            ->assertRedirect(route('restaurant.login'))
            ->assertSessionHas('status');

        $this->assertGuest('web');
        $this->assertNotSame($previousCsrfToken, $this->app['session']->token());
    }

    public function test_restaurant_dashboard_is_protected_and_super_admin_access_is_denied(): void
    {
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');

        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->get(route('restaurant.dashboard'))
            ->assertOk();

        $superAdmin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'super@example.com',
            'password' => Hash::make('SuperAdminPass1!'),
            'is_active' => true,
        ]);

        Auth::guard('web')->logout();
        $this->actingAs($superAdmin, 'super_admin')
            ->get(route('restaurant.dashboard'))
            ->assertRedirect(route('super-admin.restaurants.index'));
        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');
    }

    public function test_stale_restaurant_session_redirects_once_to_a_usable_login_page(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));

        $this->get(route('restaurant.login'))
            ->assertOk()
            ->assertSee('Welcome back');
        $this->assertGuest('web');
    }

    public function test_super_admin_and_restaurant_owner_sessions_coexist_without_a_login_redirect_loop(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);
        $superAdmin = SuperAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'super@example.com',
            'password' => Hash::make('SuperAdminPass1!'),
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin, 'super_admin');
        $this->app['session']->put('super_admin_password_version', hash('sha256', $superAdmin->getAuthPassword()));

        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');

        $this->get(route('restaurant.login'))
            ->assertRedirect(route('restaurant.dashboard'));
        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee($restaurant->name);

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertAuthenticatedAs($superAdmin, 'super_admin');
    }

    public function test_dashboard_and_subscription_status_prefer_the_current_period_over_a_future_renewal(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Renewal House',
            'slug' => 'renewal-house',
            'email' => 'renewal@example.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Renewal Owner',
            'email' => 'renewal-owner@example.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Renewal Plan',
            'code' => 'RENEWAL-TEST',
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'trial_available' => false,
            'status' => 'active',
        ]);
        $now = CarbonImmutable::now(config('app.timezone'));
        $current = RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'Current Test Period',
            'plan_code' => $plan->code,
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $now->subDay(),
            'expires_at' => $now->addDays(20),
        ]);
        RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => 'Future Renewal Period',
            'plan_code' => $plan->code,
            'price' => '499.00',
            'currency' => 'INR',
            'billing_period' => 'monthly',
            'duration_months' => 1,
            'status' => 'active',
            'is_trial' => false,
            'starts_at' => $now->addDays(21),
            'expires_at' => $now->addDays(51),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));

        $this->get(route('restaurant.dashboard'))
            ->assertOk()
            ->assertSee('Current Test Period')
            ->assertDontSee('Future Renewal Period');
        $this->get(route('restaurant.subscription'))
            ->assertOk()
            ->assertSee('Current Test Period')
            ->assertDontSee('Future Renewal Period');
        $this->assertSame($current->id, $restaurant->fresh()->currentSubscriptionAt($now)?->id);
    }

    public function test_disabled_account_and_suspended_restaurant_are_rejected(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bella Vista',
            'slug' => 'bella-vista',
            'email' => 'hello@bellavista.test',
            'status' => 'active',
        ]);
        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Owner One',
            'email' => 'owner@bellavista.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->assertAuthenticatedAs($owner, 'web');

        $owner->forceFill(['status' => 'inactive'])->save();
        $this->get(route('restaurant.dashboard'))->assertRedirect(route('restaurant.login'));
        $this->get(route('restaurant.login'))->assertOk()->assertSee('Welcome back');

        $owner->forceFill(['status' => 'active'])->save();
        $restaurant->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($owner, 'web');
        $this->app['session']->put('restaurant_user_password_version', hash('sha256', $owner->getAuthPassword()));
        $this->get(route('restaurant.dashboard'))
            ->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');
        $this->get(route('restaurant.login'))->assertOk()->assertSee('Welcome back');

        $owner->forceFill(['status' => 'inactive'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');

        $owner->forceFill(['status' => 'active'])->save();
        $restaurant->forceFill(['status' => 'active'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));
        $restaurant->forceFill(['status' => 'suspended'])->save();
        $this->get(route('restaurant.dashboard'))->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.login'));
        $this->assertGuest('web');

        $restaurant->forceFill(['status' => 'active'])->save();
        $this->post(route('restaurant.login.submit'), [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('restaurant.dashboard'));
    }

    public function test_no_public_restaurant_registration_route_exists(): void
    {
        $this->assertFalse(app('router')->has('restaurant.register'));
        $this->get('/restaurant/register')->assertNotFound();
    }
}
