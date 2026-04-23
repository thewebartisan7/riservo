<?php

namespace App\Http\Controllers\Webhooks;

use App\Support\Billing\DedupesStripeWebhookEvents;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's webhook controller with cache-layer event-id idempotency
 * (D-091, D-092). Stripe retries deliveries with the same event id;
 * Cashier itself does not dedupe. We short-circuit a second invocation
 * inside a 24-hour window (longer than Stripe's retry envelope), but ONLY
 * after the first delivery actually succeeded — a 5xx or thrown exception
 * leaves the event uncached so Stripe's retry can recover.
 *
 * The dedup logic moved into the `DedupesStripeWebhookEvents` trait in
 * PAYMENTS Session 1 (locked roadmap decision #38, D-110) so the Connect
 * webhook controller can reuse it with its own cache prefix
 * (`stripe:connect:event:`). This subscription controller now uses
 * `stripe:subscription:event:` — distinct from the Connect namespace so the
 * two cannot collide.
 */
class StripeWebhookController extends CashierWebhookController
{
    use DedupesStripeWebhookEvents;

    private const DEDUP_PREFIX = 'stripe:subscription:event:';

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;

        return $this->dedupedProcess(
            $eventId,
            self::DEDUP_PREFIX,
            fn () => parent::handleWebhook($request),
        );
    }
}
