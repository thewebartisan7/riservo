<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\Billing\FakeStripeClient;
use Tests\Support\Billing\StripeEventBuilder;

trait PaymentsTestCase
{
    use RefreshDatabase;

    protected FakeStripeClient $stripe;

    protected function setUpPaymentsBrowser(): void
    {
        $this->stripe = FakeStripeClient::forBrowser($this);
    }

    protected function paymentsWorld(): PaymentsWorld
    {
        return PaymentsWorld::default();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchStripeConnectEvent(string $type, array $payload = []): void
    {
        $accountId = (string) ($payload['account'] ?? $payload['account_id'] ?? 'acct_test_browser');
        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        $event = match (true) {
            str_starts_with($type, 'checkout.session.') => StripeEventBuilder::checkoutSessionEvent(
                $accountId,
                $type,
                (array) ($payload['session'] ?? Arr::except($payload, ['account', 'account_id', 'event_id'])),
                $eventId,
            ),
            default => array_replace_recursive([
                'id' => $eventId ?? 'evt_test_'.uniqid(),
                'object' => 'event',
                'type' => $type,
                'account' => $accountId,
                'data' => [
                    'object' => Arr::except($payload, ['account', 'account_id', 'event_id']),
                ],
            ], $payload),
        };

        $json = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = 'whsec_browser_test';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$json, $secret);

        config(['services.stripe.connect_webhook_secret' => $secret]);

        $this->call(
            'POST',
            '/webhooks/stripe-connect',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $json,
        )->assertOk();
    }
}
