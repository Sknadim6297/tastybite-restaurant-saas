<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\OwnerPasswordSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Exception\TransportException;
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
    }

    public function test_transport_failure_cleans_only_its_token_and_allows_a_safe_retry(): void
    {
        $owner = $this->createOwner();
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
}
