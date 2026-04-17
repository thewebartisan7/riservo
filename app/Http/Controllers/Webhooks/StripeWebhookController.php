<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cashier's webhook controller with cache-layer event-id idempotency
 * (D-091, D-092). Stripe retries deliveries with the same event id;
 * Cashier itself does not dedupe. We short-circuit a second invocation
 * inside a 24-hour window (longer than Stripe's retry envelope), but ONLY
 * after the first delivery actually succeeded — a 5xx or thrown exception
 * leaves the event uncached so Stripe's retry can recover.
 */
class StripeWebhookController extends CashierWebhookController
{
    private const DEDUP_PREFIX = 'stripe:event:';

    private const DEDUP_TTL_SECONDS = 86400;

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $cacheKey = $eventId !== null ? self::DEDUP_PREFIX.$eventId : null;

        if ($cacheKey !== null && Cache::has($cacheKey)) {
            return new Response('Webhook already processed.', 200);
        }

        try {
            $response = parent::handleWebhook($request);
        } catch (Throwable $e) {
            // Don't poison the dedup cache — let Stripe retry deliver this
            // event again so we can recover from transient DB / Stripe issues.
            throw $e;
        }

        // Mark processed only on a successful delivery (2xx). Anything else
        // (4xx from Cashier's missingMethod, 5xx from a downstream issue)
        // leaves the cache untouched so Stripe's retry path can recover.
        if ($cacheKey !== null && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);
        }

        return $response;
    }
}
