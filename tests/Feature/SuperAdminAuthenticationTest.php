<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StrongAdminPass1!';

    public function test_guest_can_view_the_login_page_with_sensitive_cache_headers(): void
    {
        $response = $this->get(route('super-admin.login'));

        $response
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('assets/app-font.css', false)
            ->assertSee('fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(1, substr_count($response->getContent(), 'fonts.googleapis.com/css2?family=Inter'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_invalid_credentials_receive_a_generic_error_without_password_input(): void
    {
        $this->withHeader('referer', 'https://untrusted.example/redirect')
            ->post(route('super-admin.login.submit'), [
                'email' => 'unknown@example.com',
                'password' => self::PASSWORD,
            ])
            ->assertRedirect(route('super-admin.login'))
            ->assertSessionHasErrors(['email'])
            ->assertSessionHas('_old_input.email', 'unknown@example.com')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_valid_credentials_regenerate_the_session_and_open_the_dashboard(): void
    {
        $admin = $this->createSuperAdmin();
        $this->get(route('super-admin.login'));
        $previousSessionId = $this->app['session']->getId();

        $this->post(route('super-admin.login.submit'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])
            ->assertRedirect(route('super-admin.dashboard'))
            ->assertSessionHas('status', 'Welcome back.');

        $this->assertNotSame($previousSessionId, $this->app['session']->getId());
        $this->assertAuthenticatedAs($admin, 'super_admin');
        $response = $this->get(route('super-admin.dashboard'));
        $response->assertOk()
            ->assertSee('Your platform starts here.')
            ->assertSee('Reports')
            ->assertSee('Activity Logs')
            ->assertSee('Settings')
            ->assertDontSee('total restaurants', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_super_admin_passwords_are_hashed_at_rest(): void
    {
        $admin = $this->createSuperAdmin();

        $this->assertNotSame(self::PASSWORD, $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->getRawOriginal('password')));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('super-admin.login.submit'), [
                'email' => 'unknown@example.com',
                'password' => self::PASSWORD,
            ])->assertRedirect(route('super-admin.login'));
        }

        $this->post(route('super-admin.login.submit'), [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ])->assertTooManyRequests();
    }

    public function test_logout_clears_authentication_invalidates_the_session_and_regenerates_csrf(): void
    {
        $admin = $this->createSuperAdmin();
        $this->signIn($admin);
        $previousCsrfToken = $this->app['session']->token();

        $this->post(route('super-admin.logout'))
            ->assertRedirect(route('super-admin.login'))
            ->assertSessionHas('status', 'You have been signed out.');

        $this->assertGuest('super_admin');
        $this->assertNotSame($previousCsrfToken, $this->app['session']->token());
        $this->get(route('super-admin.dashboard'))
            ->assertRedirect(route('super-admin.login'));
    }

    public function test_dashboard_and_profile_are_protected_and_profile_shows_only_the_signed_in_admin(): void
    {
        $admin = $this->createSuperAdmin();

        $this->get(route('super-admin.dashboard'))
            ->assertRedirect(route('super-admin.login'));
        $this->get(route('super-admin.profile'))
            ->assertRedirect(route('super-admin.login'));

        $this->signIn($admin);
        $this->get(route('super-admin.profile'))
            ->assertOk()
            ->assertSee($admin->name)
            ->assertSee($admin->email)
            ->assertDontSee($admin->getRawOriginal('password'));
    }

    public function test_restaurant_user_cannot_access_the_super_admin_dashboard(): void
    {
        $restaurantUser = User::create([
            'name' => 'Restaurant User',
            'email' => 'restaurant@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->actingAs($restaurantUser, 'web')
            ->get(route('super-admin.dashboard'))
            ->assertRedirect(route('super-admin.login'));

        $this->assertGuest('super_admin');
        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_disabled_admin_cannot_sign_in_or_reuse_an_existing_session(): void
    {
        $admin = $this->createSuperAdmin();
        $this->signIn($admin);
        $this->assertDatabaseHas('super_admins', ['id' => $admin->id, 'is_active' => true]);

        $admin->forceFill(['is_active' => false])->save();
        Auth::forgetGuards();

        $this->get(route('super-admin.dashboard'))
            ->assertRedirect(route('super-admin.login'));
        $this->assertGuest('super_admin');

        $this->post(route('super-admin.login.submit'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors(['email']);
    }

    public function test_password_change_invalidates_existing_super_admin_sessions(): void
    {
        $admin = $this->createSuperAdmin();
        $this->signIn($admin);

        $admin->forceFill(['password' => 'ChangedAdminPass2!'])->save();
        Auth::forgetGuards();

        $this->get(route('super-admin.dashboard'))
            ->assertRedirect(route('super-admin.login'));
        $this->assertGuest('super_admin');
    }

    public function test_no_public_super_admin_registration_route_exists(): void
    {
        $this->assertFalse(app('router')->has('super-admin.register'));
        $this->get('/super-admin/register')->assertNotFound();
    }

    public function test_super_admin_routes_remain_inside_the_csrf_protected_web_middleware_group(): void
    {
        $route = app('router')->getRoutes()->getByName('super-admin.login.submit');

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(
            ValidateCsrfToken::class,
            app(Kernel::class)->getMiddlewareGroups()['web']
        );
    }

    public function test_csrf_middleware_rejects_a_post_without_a_matching_token(): void
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $request = Request::create('/super-admin/login', 'POST');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('This should not be reached.'));
    }

    private function createSuperAdmin(array $attributes = []): SuperAdmin
    {
        return SuperAdmin::create(array_merge([
            'name' => 'TastyBite Admin',
            'email' => 'admin@example.com',
            'password' => self::PASSWORD,
            'is_active' => true,
        ], $attributes));
    }

    private function signIn(SuperAdmin $admin): void
    {
        $this->post(route('super-admin.login.submit'), [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('super-admin.dashboard'));
    }
}
