<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your login link for riservo'))
            ->line(__('Click the button below to log in to your account.'))
            ->action(__('Log in'), $this->url)
            ->line(__('This link will expire in 15 minutes and can only be used once.'))
            ->line(__('If you did not request this link, no action is needed.'));
    }
}
