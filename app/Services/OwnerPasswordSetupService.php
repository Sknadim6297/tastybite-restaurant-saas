<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class OwnerPasswordSetupService
{
    /**
     * @return array{sent: bool, result: string, message: string, toast: string}
     */
    public function send(User $owner): array
    {
        $mailer = config('mail.default');

        if ($this->usesLogTransport($mailer)) {
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
            return [
                'sent' => false,
                'result' => 'non_delivery_transport_not_allowed',
                'toast' => 'warning',
                'message' => 'The array mail transport captures messages in memory and does not deliver them. Configure a verified SMTP/API mail transport before inviting the owner.',
            ];
        }

        $configurationTransport = config("mail.mailers.{$mailer}.transport");

        try {
            $status = Password::broker('users')->sendResetLink(['email' => $owner->email]);
        } catch (TransportExceptionInterface) {
            return [
                'sent' => false,
                'result' => 'transport_failed',
                'toast' => 'warning',
                'message' => 'The configured mail transport rejected the password setup email. Check mail connectivity and retry.',
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

    private function usesLogTransport(string $mailer): bool
    {
        $configuration = config("mail.mailers.{$mailer}", []);

        if (($configuration['transport'] ?? null) === 'log') {
            return true;
        }

        if (($configuration['transport'] ?? null) === 'failover') {
            foreach ($configuration['mailers'] ?? [] as $fallback) {
                if ($this->usesLogTransport($fallback)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function usesArrayTransport(string $mailer): bool
    {
        $configuration = config("mail.mailers.{$mailer}", []);

        if (($configuration['transport'] ?? null) === 'array') {
            return true;
        }

        if (($configuration['transport'] ?? null) === 'failover') {
            foreach ($configuration['mailers'] ?? [] as $fallback) {
                if ($this->usesArrayTransport($fallback)) {
                    return true;
                }
            }
        }

        return false;
    }
}
