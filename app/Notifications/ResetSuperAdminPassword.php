<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetSuperAdminPassword extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('super-admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your TastyBite Super Admin password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your Super Admin password.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you did not request a reset, no action is needed.');
    }
}
