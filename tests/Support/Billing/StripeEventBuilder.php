<?php

namespace Tests\Support\Billing;

/**
 * PAYMENTS Session 3 — canonical Stripe Event payload builders for
 * webhook-arm tests.
 *
 * The `StripeConnectWebhookController` tolerates unsigned payloads in the
 * `testing` environment (per D-120), so tests POST these JSON structures
 * directly. This builder centralises the shapes Session 3 needs:
 *
 *  - `disputeEvent($accountId, 'charge.dispute.created' | '.updated' |
 *    '.closed')` — mirrors the Stripe dispute object shape.
 *  - `refundEvent($accountId, 'charge.refunded' | 'charge.refund.updated' |
 *    'refund.updated')` — the former wraps the refund in a Charge object's
 *    `refunds->data[]`; the latter two carry the Refund directly.
 *
 * Each builder returns a plain associative array ready for
 * `$this->postJson('/webhooks/stripe-connect', $payload)`. Overrides let
 * individual tests tweak specific fields (status, failure_reason, ...).
 */
class StripeEventBuilder
{
    /**
     * @param  array<string, mixed>  $sessionOverrides  merged into the Checkout Session object
     * @return array<string, mixed>
     */
    public static function checkoutSessionEvent(
        string $accountId,
        string $type,
        array $sessionOverrides = [],
        ?string $eventId = null,
    ): array {
        $sessionId = (string) ($sessionOverrides['id'] ?? 'cs_test_'.uniqid());
        $eventId ??= 'evt_test_'.uniqid();

        $session = array_merge([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'account' => $accountId,
            'client_reference_id' => null,
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'currency' => 'chf',
            'payment_intent' => 'pi_test_'.uniqid(),
            'latest_charge' => 'ch_test_'.uniqid(),
        ], $sessionOverrides);

        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'account' => $accountId,
            'data' => [
                'object' => $session,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides  merged into the dispute object (inner merge)
     * @return array<string, mixed>
     */
    public static function disputeEvent(
        string $accountId,
        string $type,
        ?string $disputeId = null,
        ?string $paymentIntentId = null,
        array $overrides = [],
    ): array {
        $disputeId ??= 'dp_test_'.uniqid();

        $dispute = array_merge([
            'id' => $disputeId,
            'object' => 'dispute',
            'amount' => 5000,
            'charge' => 'ch_test_'.uniqid(),
            'currency' => 'chf',
            'payment_intent' => $paymentIntentId,
            'reason' => 'fraudulent',
            'status' => $type === 'charge.dispute.closed' ? 'won' : 'warning_needs_response',
            'evidence_details' => [
                'due_by' => time() + 7 * 86400,
            ],
        ], $overrides);

        return [
            'id' => 'evt_test_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'account' => $accountId,
            'data' => [
                'object' => $dispute,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $refundOverrides  merged into the Refund object
     * @return array<string, mixed>
     */
    public static function refundEvent(
        string $accountId,
        string $type,
        string $stripeRefundId,
        array $refundOverrides = [],
        ?string $eventId = null,
    ): array {
        $refund = array_merge([
            'id' => $stripeRefundId,
            'object' => 'refund',
            'amount' => 5000,
            'currency' => 'chf',
            'status' => 'succeeded',
            'failure_reason' => null,
            'payment_intent' => 'pi_test_'.uniqid(),
        ], $refundOverrides);

        $eventId ??= 'evt_test_'.uniqid();

        if ($type === 'charge.refunded') {
            // `charge.refunded` wraps the Refund in a Charge object whose
            // `refunds->data[]` carries the refund list.
            $chargeObject = [
                'id' => 'ch_test_'.uniqid(),
                'object' => 'charge',
                'refunds' => [
                    'object' => 'list',
                    'data' => [$refund],
                    'has_more' => false,
                ],
            ];

            return [
                'id' => $eventId,
                'object' => 'event',
                'type' => $type,
                'account' => $accountId,
                'data' => [
                    'object' => $chargeObject,
                ],
            ];
        }

        // `charge.refund.updated` and `refund.updated` carry a Refund directly.
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'account' => $accountId,
            'data' => [
                'object' => $refund,
            ],
        ];
    }
}
