<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\ResetRestaurantPassword;
use App\Services\OwnerPasswordSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use ArrayObject;
use Tests\TestCase;

class OwnerPasswordSetupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_and_failover_to_log_mailers_are_blocked_before_token_creation(): void
    {
        $owner = $this->createOwner();
        $service = app(OwnerPasswordSetupService::class);

        config(['mail.default' => 'log']);
        $this->assertSame('log_transport_not_allowed', $service->send($owner)['result']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);

        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['roundrobin'],
            ],
            'mail.mailers.roundrobin' => [
                'transport' => 'roundrobin',
                'mailers' => ['smtp', 'log'],
            ],
        ]);
        Notification::fake();

        $this->assertSame('log_transport_not_allowed', $service->sendForEmail($owner->email)['result']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        Notification::assertNothingSent();

        config([
            'mail.mailers.failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'array'],
            ],
        ]);
        $this->assertSame('non_delivery_transport_not_allowed', $service->sendForEmail($owner->email)['result']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_smtp_transport_receives_reset_email_with_correct_recipient_token_and_expiry(): void
    {
        $owner = $this->createOwner();
        $attempts = new ArrayObject;
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => null,
                'password' => null,
                'require_tls' => true,
            ],
        ]);
        $this->configureRecordingSmtp($attempts);
        Log::spy();

        $result = app(OwnerPasswordSetupService::class)->send($owner);

        $this->assertSame('accepted_by_transport', $result['result']);
        $this->assertCount(1, $attempts);
        $message = $attempts[0]['message'];
        $envelope = $attempts[0]['envelope'];
        $query = [];
        $this->assertInstanceOf(Email::class, $message);
        $this->assertInstanceOf(Envelope::class, $envelope);
        $this->assertSame([$owner->email], array_map(
            static fn ($address): string => $address->getAddress(),
            $envelope->getRecipients(),
        ));
        $html = html_entity_decode($message->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('~href="([^"]*restaurant/reset-password/[^"]+)"~', $html, $matches));
        $resetUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
        $this->assertSame($owner->email, $query['email'] ?? null);
        $token = basename((string) parse_url($resetUrl, PHP_URL_PATH));
        $this->assertTrue(Password::broker('users')->tokenExists($owner, $token));
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertNotInstanceOf(ShouldQueue::class, new ResetRestaurantPassword($token));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);

        Log::shouldHaveReceived('info')
            ->with('Owner password reset email delivery attempt started.', \Mockery::on(
                fn (array $context): bool => $context['category'] === 'mail_delivery_attempt'
                    && $context['owner_id'] === $owner->id
                    && ! array_key_exists('email', $context)
                    && ! array_key_exists('token', $context)
                    && ! array_key_exists('password', $context)
            ))
            ->once();
        Log::shouldHaveReceived('info')
            ->with('Owner password reset email accepted by configured mail transport.', \Mockery::on(
                fn (array $context): bool => $context['category'] === 'mail_delivery_accepted'
                    && $context['owner_id'] === $owner->id
                    && ! array_key_exists('email', $context)
                    && ! array_key_exists('token', $context)
                    && ! array_key_exists('password', $context)
            ))
            ->once();
    }

    public function test_failover_uses_a_valid_secondary_smtp_transport_after_primary_failure(): void
    {
        $owner = $this->createOwner();
        $attempts = new ArrayObject;
        config([
            'mail.default' => 'failover',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'primary.example.test', 'port' => 587],
            'mail.mailers.smtp_backup' => ['transport' => 'smtp', 'host' => 'backup.example.test', 'port' => 587],
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'smtp_backup']],
        ]);
        $this->configureRecordingSmtp($attempts, ['primary.example.test']);

        $result = app(OwnerPasswordSetupService::class)->send($owner);

        $this->assertSame('accepted_by_transport', $result['result']);
        $this->assertSame([
            'primary.example.test',
            'backup.example.test',
        ], array_column($attempts->getArrayCopy(), 'host'));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_transport_failure_cleans_only_its_token_and_allows_a_safe_retry(): void
    {
        $owner = $this->createOwner();
        config(['mail.default' => 'array']);
        Log::spy();

        $service = new class extends OwnerPasswordSetupService
        {
            public bool $failNextNotification = true;

            protected function sendNotification(User $owner, string $token): void
            {
                if ($this->failNextNotification) {
                    $this->failNextNotification = false;
                    throw new TransportException('synthetic-secret-transport-detail');
                }

                parent::sendNotification($owner, $token);
            }
        };

        $this->app->instance(OwnerPasswordSetupService::class, $service);
        $this->post(route('restaurant.password.email'), ['email' => $owner->email])
            ->assertRedirect(route('restaurant.password.request'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Owner password reset email transport failed.', \Mockery::on(
                fn (array $context): bool => $context['category'] === 'mail_transport_failure'
                    && $context['mailer'] === 'array'
                    && $context['owner_id'] === $owner->id
                    && ! array_key_exists('exception', $context)
                    && ! array_key_exists('token', $context)
                    && ! array_key_exists('email', $context)
            ));

        $retryResult = $service->send($owner);

        $this->assertSame('captured_by_test_transport', $retryResult['result']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_broker_throttles_repeat_reset_requests_without_sending_another_notification(): void
    {
        $owner = $this->createOwner();
        config(['mail.default' => 'array']);
        $service = app(OwnerPasswordSetupService::class);

        $firstResult = $service->send($owner);
        $secondResult = $service->sendForEmail($owner->email);

        $this->assertSame('captured_by_test_transport', $firstResult['result']);
        $this->assertSame(Password::RESET_THROTTLED, $secondResult['result']);
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
    }

    private function createOwner(): User
    {
        $restaurant = Restaurant::create([
            'name' => 'Mail Test Restaurant',
            'slug' => 'mail-test-restaurant',
            'email' => 'restaurant@mail-test.test',
            'status' => 'active',
        ]);

        return User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Mail Test Owner',
            'email' => 'owner@mail-test.test',
            'role' => 'owner',
            'status' => 'active',
            'password' => Hash::make('InitialOwnerPassword1!'),
        ]);
    }

    /**
     * Register a recording transport under the SMTP driver name so the real
     * notification and failover pipeline run without opening a network connection.
     *
     * @param list<string> $failedHosts
     */
    private function configureRecordingSmtp(ArrayObject $attempts, array $failedHosts = []): void
    {
        Mail::extend('smtp', static function (array $config) use ($attempts, $failedHosts): AbstractTransport {
            return new class ((string) ($config['host'] ?? ''), $attempts, $failedHosts) extends AbstractTransport
            {
                public function __construct(
                    private readonly string $host,
                    private readonly ArrayObject $attempts,
                    private readonly array $failedHosts,
                ) {
                    parent::__construct();
                }

                protected function doSend(SentMessage $message): void
                {
                    $this->attempts->append([
                        'host' => $this->host,
                        'message' => $message->getOriginalMessage(),
                        'envelope' => $message->getEnvelope(),
                    ]);

                    if (in_array($this->host, $this->failedHosts, true)) {
                        throw new TransportException('Synthetic SMTP handoff failure.');
                    }
                }

                public function __toString(): string
                {
                    return 'recording-smtp';
                }
            };
        });
        app('mail.manager')->forgetMailers();
    }
}
