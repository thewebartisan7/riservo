<?php

namespace App\Notifications;

use App\Models\BusinessInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly BusinessInvitation $invitation,
        public readonly string $businessName,
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
        $url = route('invitation.show', ['token' => $this->invitation->token]);

        return (new MailMessage)
            ->subject(__('Join :business on riservo', ['business' => $this->businessName]))
            ->line(__('You have been invited to join :business as a :role.', [
                'business' => $this->businessName,
                'role' => $this->invitation->role->value,
            ]))
            ->action(__('Accept invitation'), $url)
            ->line(__('This invitation expires in :hours hours.', [
                'hours' => BusinessInvitation::EXPIRY_HOURS,
            ]));
    }
}
