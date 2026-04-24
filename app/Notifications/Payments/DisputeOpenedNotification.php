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
 * Dispatched on `charge.dispute.created` to business admins only (money
 * surfaces are admin-only per locked decisions #19 / #35). Carries the
 * dispute reason, amount + currency, evidence due-by, and a deep-link to
 * the Stripe Express dispute evidence UI — riservo does NOT build an
 * in-app dispute evidence flow (#25).
 *
 * `$booking` may be null when the dispute could not be resolved to a
 * booking via the charge id (rare — typically covered when the booking
 * carries a `stripe_payment_intent_id` matching the dispute's charge).
 */
class DisputeOpenedNotification extends Notification implements ShouldQueue
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
        $payload = $this->pendingAction->payload;
        $this->pendingAction->loadMissing('business');
        $business = $this->pendingAction->business;

        $reason = is_string($payload['reason'] ?? null) ? (string) $payload['reason'] : 'unknown';
        $amount = (int) ($payload['amount'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? 'chf'));
        $amountFormatted = $currency.' '.number_format($amount / 100, 2, '.', "'");
        $evidenceDueBy = isset($payload['evidence_due_by']) && is_numeric($payload['evidence_due_by'])
            ? date('d.m.Y', (int) $payload['evidence_due_by'])
            : null;

        $disputeId = is_string($payload['dispute_id'] ?? null) ? $payload['dispute_id'] : null;
        $accountId = $this->booking?->stripe_connected_account_id;
        $stripeDeepLink = ($disputeId !== null && is_string($accountId) && $accountId !== '')
            ? "https://dashboard.stripe.com/{$accountId}/disputes/{$disputeId}"
            : null;

        $this->booking?->loadMissing(['customer', 'service']);

        return (new MailMessage)
            ->subject(__('A dispute has been opened — :business', [
                'business' => $business->name ?? config('app.name'),
            ]))
            ->markdown('mail.payments.dispute-opened', [
                'businessName' => $business->name ?? config('app.name'),
                'reason' => $reason,
                'amountFormatted' => $amountFormatted,
                'evidenceDueBy' => $evidenceDueBy,
                'stripeDeepLink' => $stripeDeepLink,
                'customerName' => $this->booking?->customer?->name,
                'customerEmail' => $this->booking?->customer?->email,
                'serviceName' => $this->booking?->service?->name,
                'bookingStartsAt' => $this->booking?->starts_at?->setTimezone($business !== null ? $business->timezone : 'UTC')->format('d.m.Y H:i'),
            ]);
    }
}
