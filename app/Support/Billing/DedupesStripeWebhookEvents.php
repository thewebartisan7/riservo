<?php

namespace App\Support\Billing;

use Closure;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cache-layer event-id idempotency for Stripe webhooks (D-092).
 *
 * Originally inlined in `StripeWebhookController::handleWebhook` for the
 * MVPC-3 subscription endpoint. PAYMENTS Session 1 (locked roadmap decision
 * #38, D-110) extracted it so the new Connect webhook controller can reuse
 * the same dedup logic with its own cache namespace. The two prefixes
 * (`stripe:subscription:event:` and `stripe:connect:event:`) cannot collide
 * even if Stripe ever emits the same event ID across the platform and a
 * connected account.
 *
 * Contract:
 *  - Returns 200 immediately if the event id is already cached.
 *  - Caches the event id only on a 2xx response, so transient failures still
 *    permit Stripe's retry path to recover.
 *  - Re-throws any exception from $process — the cache is left untouched so
 *    Stripe retries deliver the event again.
 */
trait DedupesStripeWebhookEvents
{
    private const DEDUP_TTL_SECONDS = 86400;

    /**
     * @param  string|null  $eventId  Stripe event.id from the payload (null = no dedup possible).
     * @param  string  $cachePrefix  Per-source namespace, e.g. 'stripe:connect:event:'.
     * @param  Closure(): Response  $process  Handler that runs on the first delivery.
     */
    protected function dedupedProcess(?string $eventId, string $cachePrefix, Closure $process): Response
    {
        $cacheKey = $eventId !== null ? $cachePrefix.$eventId : null;

        if ($cacheKey !== null && Cache::has($cacheKey)) {
            return new Response('Webhook already processed.', 200);
        }

        try {
            $response = $process();
        } catch (Throwable $e) {
            throw $e;
        }

        if ($cacheKey !== null && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);
        }

        return $response;
    }
}
