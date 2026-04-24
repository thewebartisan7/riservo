<?php

namespace App\Notifications\Payments;

use App\Models\Booking;
use App\Models\PendingAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PAYMENTS Session 3 — locked roadmap decision #35.
 *
 * Dispatched on `charge.dispute.closed` to business admins only. Carries
 * the final outcome (won / lost / warning_closed / etc.) derived from the
 * PA's `resolution_note` (format: `closed:<stripe_status>`).
 */
class DisputeClosedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?Booking $booking,
        public PendingAction $pendingAction,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resolutionNote = $this->pendingAction->resolution_note ?? '';
        $stripeStatus = str_starts_with($resolutionNote, 'closed:')
            ? substr($resolutionNote, 7)
            : 'unknown';
        $outcomeLabel = $this->outcomeLabel($stripeStatus);

        $payload = $this->pendingAction->payload;
        $disputeId = is_string($payload['dispute_id'] ?? null) ? $payload['dispute_id'] : null;
        $accountId = $this->booking?->stripe_connected_account_id;
        $stripeDeepLink = ($disputeId !== null && is_string($accountId) && $accountId !== '')
            ? "https://dashboard.stripe.com/{$accountId}/disputes/{$disputeId}"
            : null;

        $this->pendingAction->loadMissing('business');
        $this->booking?->loadMissing(['customer', 'service']);
        $business = $this->pendingAction->business;

        return (new MailMessage)
            ->subject(__('Dispute resolved — :outcome', ['outcome' => $outcomeLabel]))
            ->markdown('mail.payments.dispute-closed', [
                'businessName' => $business->name ?? config('app.name'),
                'outcomeLabel' => $outcomeLabel,
                'stripeStatus' => $stripeStatus,
                'stripeDeepLink' => $stripeDeepLink,
                'customerName' => $this->booking?->customer?->name,
                'serviceName' => $this->booking?->service?->name,
            ]);
    }

    private function outcomeLabel(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'won' => (string) __('won'),
            'lost' => (string) __('lost'),
            'warning_closed' => (string) __('closed'),
            'warning_needs_response' => (string) __('needs response'),
            default => (string) __('unknown'),
        };
    }
}
