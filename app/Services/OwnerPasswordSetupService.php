<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class OwnerPasswordSetupService
{
    /**
     * @return array{sent: bool, result: string, message: string, toast: string}
     */
    public function sendForEmail(string $email): array
    {
        return $this->sendResetLink(Str::lower(trim($email)));
    }

    /**
     * @return array{sent: bool, result: string, message: string, toast: string}
     */
    public function send(User $owner): array
    {
        return $this->sendResetLink($owner->email, $owner);
    }

    /**
     * @return array{sent: bool, result: string, message: string, toast: string}
     */
    private function sendResetLink(string $email, ?User $expectedOwner = null): array
    {
        $mailer = config('mail.default');

        if ($this->usesLogTransport($mailer)) {
            Log::warning('Owner password reset email blocked by non-delivery mail transport.', [
                'category' => 'log_transport_blocked',
                'mailer' => $mailer,
            ]);

            return [
                'sent' => false,
                'result' => 'log_transport_not_allowed',
                'toast' => 'warning',
                'message' => "The password setup link was not sent: MAIL_MAILER is configured to use a log transport ({$mailer}). Configure a verified mail transport, then retry. Automated tests may use the in-memory array transport; it does not deliver email.",
            ];
        }

        if (
            $this->usesArrayTransport($mailer)
            && ($mailer !== 'array' || ! app()->environment('testing'))
        ) {
            Log::warning('Owner password reset email blocked by non-delivery mail transport.', [
                'category' => 'non_delivery_transport_blocked',
                'mailer' => $mailer,
            ]);

            return [
                'sent' => false,
                'result' => 'non_delivery_transport_not_allowed',
                'toast' => 'warning',
                'message' => 'The array mail transport captures messages in memory and does not deliver them. Configure a verified SMTP/API mail transport before inviting the owner.',
            ];
        }

        $configurationTransport = config("mail.mailers.{$mailer}.transport");
        $issuedToken = null;
        $ownerId = $expectedOwner?->id;

        try {
            $broker = Password::broker('users');
            $tokenTable = config('auth.passwords.users.table');
            $status = $broker->sendResetLink(['email' => $email], function ($user, string $token) use (
                $broker,
                $email,
                $tokenTable,
                $mailer,
                $configurationTransport,
                &$issuedToken,
                &$ownerId,
            ): string {
                if (! $user instanceof User || Str::lower($user->email) !== $email) {
                    return Password::INVALID_USER;
                }

                $ownerId = $user->id;
                $record = DB::table($tokenTable)
                    ->where('email', $user->getEmailForPasswordReset())
                    ->first(['token']);

                if (! $record || ! Hash::check($token, $record->token)) {
                    return Password::RESET_THROTTLED;
                }

                $issuedToken = [
                    'email' => $user->getEmailForPasswordReset(),
                    'hash' => $record->token,
                ];

                Log::info('Owner password reset email delivery attempt started.', [
                    'category' => 'mail_delivery_attempt',
                    'mailer' => $mailer,
                    'transport' => $configurationTransport,
                    'owner_id' => $ownerId,
                ]);
                $this->sendNotification($user, $token);
                event(new PasswordResetLinkSent($user));

                return Password::RESET_LINK_SENT;
            });
        } catch (TransportExceptionInterface) {
            $this->deleteIssuedToken($issuedToken, $mailer, $ownerId);
            Log::warning('Owner password reset email transport failed.', [
                'category' => 'mail_transport_failure',
                'mailer' => $mailer,
                'transport' => $configurationTransport,
                'owner_id' => $ownerId,
            ]);

            return [
                'sent' => false,
                'result' => 'transport_failed',
                'toast' => 'warning',
                'message' => 'The configured mail transport rejected the password setup email. Check mail connectivity and retry.',
            ];
        }

        if ($status === Password::INVALID_USER) {
            return [
                'sent' => false,
                'result' => 'invalid_user',
                'toast' => 'warning',
                'message' => 'A password setup link could not be issued. Verify the owner account and password-reset configuration before retrying.',
            ];
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return [
                'sent' => false,
                'result' => (string) $status,
                'toast' => 'warning',
                'message' => 'A password setup link could not be issued. Verify the owner account and password-reset configuration before retrying.',
            ];
        }

        Log::info('Owner password reset email accepted by configured mail transport.', [
            'category' => 'mail_delivery_accepted',
            'mailer' => $mailer,
            'transport' => $configurationTransport,
            'owner_id' => $ownerId,
        ]);

        if ($configurationTransport === 'array') {
            return [
                'sent' => false,
                'result' => 'captured_by_test_transport',
                'toast' => 'information',
                'message' => 'The setup notification was captured by the in-memory test mail transport. It was not delivered to the owner.',
            ];
        }

        return [
            'sent' => true,
            'result' => 'accepted_by_transport',
            'toast' => 'success',
            'message' => 'The one-time password setup email was accepted by the configured mail transport.',
        ];
    }

    protected function sendNotification(User $owner, string $token): void
    {
        $owner->sendPasswordResetNotification($token);
    }

    /**
     * Delete only the token hash created for the failed notification.
     *
     * @param array{email: string, hash: string}|null $issuedToken
     */
    private function deleteIssuedToken(?array $issuedToken, string $mailer, ?int $ownerId): void
    {
        if ($issuedToken === null) {
            return;
        }

        try {
            DB::table(config('auth.passwords.users.table'))
                ->where('email', $issuedToken['email'])
                ->where('token', $issuedToken['hash'])
                ->delete();
        } catch (QueryException) {
            Log::error('Owner password reset token cleanup failed after mail transport failure.', [
                'category' => 'reset_token_cleanup_failure',
                'mailer' => $mailer,
                'owner_id' => $ownerId,
            ]);
        }
    }

    private function usesLogTransport(string $mailer): bool
    {
        return $this->usesTransport($mailer, 'log');
    }

    private function usesArrayTransport(string $mailer): bool
    {
        return $this->usesTransport($mailer, 'array');
    }

    /**
     * Check composed mailers recursively while tolerating invalid/circular definitions.
     *
     * @param array<string, true> $visited
     */
    private function usesTransport(string $mailer, string $transport, array $visited = []): bool
    {
        if (isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $configuration = config("mail.mailers.{$mailer}", []);

        if (($configuration['transport'] ?? null) === $transport) {
            return true;
        }

        foreach ($configuration['mailers'] ?? [] as $nestedMailer) {
            if (is_string($nestedMailer) && $this->usesTransport($nestedMailer, $transport, $visited)) {
                return true;
            }
        }

        return false;
    }
}
