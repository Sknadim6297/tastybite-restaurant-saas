<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SuperAdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StrongAdminPass1!';

    public function test_reset_request_uses_the_local_array_mail_transport_and_does_not_disclose_account_existence(): void
    {
        $this->post(route('super-admin.password.email'), ['email' => 'missing@example.com'])
            ->assertSessionHas('status', 'If an active Super Admin account matches that email, password reset instructions will be sent.');

        $transport = $this->arrayTransport();
        $this->assertCount(0, $transport->messages());

        $admin = $this->createSuperAdmin();
        $this->post(route('super-admin.password.email'), ['email' => $admin->email])
            ->assertSessionHas('status', 'If an active Super Admin account matches that email, password reset instructions will be sent.');

        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('Reset your TastyBite Super Admin password', $email->getSubject());
        $this->assertStringContainsString('reset-password/', $email->getHtmlBody());
        $this->assertStringContainsString('email=admin%40example.com', $email->getHtmlBody());
    }

    public function test_reset_delivery_is_refused_when_mail_would_be_written_to_logs(): void
    {
        $this->createSuperAdmin();
        config(['mail.default' => 'log']);

        $this->post(route('super-admin.password.email'), ['email' => 'admin@example.com'])
            ->assertRedirect(route('super-admin.password.request'))
            ->assertSessionHasErrors(['email']);

        $this->assertDatabaseCount('super_admin_password_reset_tokens', 0);
    }

    public function test_invalid_token_is_rejected_with_a_generic_message(): void
    {
        $admin = $this->createSuperAdmin();

        $this->from(route('super-admin.password.reset', [
            'token' => 'not-a-valid-token',
            'email' => $admin->email,
        ]))->post(route('super-admin.password.update'), [
            'token' => 'not-a-valid-token',
            'email' => $admin->email,
            'password' => 'AnotherStrongPass2!',
            'password_confirmation' => 'AnotherStrongPass2!',
        ])
            ->assertRedirect(route('super-admin.password.reset', [
                'token' => 'not-a-valid-token',
                'email' => $admin->email,
            ]))
            ->assertSessionHasErrors(['email']);

        $this->assertTrue(password_verify(self::PASSWORD, $admin->fresh()->getRawOriginal('password')));
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $admin = $this->createSuperAdmin();
        $token = $this->requestResetToken($admin);

        $this->travel(61)->minutes();

        $this->post(route('super-admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'AnotherStrongPass2!',
            'password_confirmation' => 'AnotherStrongPass2!',
        ])->assertSessionHasErrors(['email']);

        $this->assertTrue(password_verify(self::PASSWORD, $admin->fresh()->getRawOriginal('password')));
    }

    public function test_successful_reset_changes_password_and_invalidates_the_single_use_token(): void
    {
        $admin = $this->createSuperAdmin();
        $token = $this->requestResetToken($admin);
        $newPassword = 'FreshAdminPassword3!';

        $this->post(route('super-admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertRedirect(route('super-admin.login'))
            ->assertSessionHas('status', 'Your password has been reset. Sign in with your new password.');

        $this->assertTrue(password_verify($newPassword, $admin->fresh()->getRawOriginal('password')));
        $this->assertDatabaseMissing('super_admin_password_reset_tokens', ['email' => $admin->email]);

        $this->post(route('super-admin.password.update'), [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'YetAnotherStrong4!',
            'password_confirmation' => 'YetAnotherStrong4!',
        ])->assertSessionHasErrors(['email']);
    }

    public function test_reset_password_requires_confirmation_and_the_strength_policy(): void
    {
        $this->createSuperAdmin();

        $this->post(route('super-admin.password.update'), [
            'token' => 'not-used',
            'email' => 'admin@example.com',
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['password']);
    }

    private function createSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'TastyBite Admin',
            'email' => 'admin@example.com',
            'password' => self::PASSWORD,
            'is_active' => true,
        ]);
    }

    private function requestResetToken(SuperAdmin $admin): string
    {
        $this->post(route('super-admin.password.email'), ['email' => $admin->email])
            ->assertSessionHas('status');

        $message = $this->arrayTransport()->messages()->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $html = html_entity_decode($message->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame(1, preg_match('~href="([^"]*super-admin/reset-password/[^"]+)"~', $html, $matches));
        $query = [];
        parse_str((string) parse_url($matches[1], PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($matches[1], PHP_URL_PATH));

        $this->assertSame($admin->email, $query['email'] ?? null);
        $this->assertNotSame('', $token);

        return $token;
    }

    private function arrayTransport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}
