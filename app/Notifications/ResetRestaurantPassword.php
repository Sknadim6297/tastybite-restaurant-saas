<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetRestaurantPassword extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expirationMinutes = (int) config('auth.passwords.users.expire', 60);
        $url = route('restaurant.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your TastyBite Restaurant Admin password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset the password for your restaurant management account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$expirationMinutes} minutes. If you did not request a reset, no action is needed.");
    }
}
