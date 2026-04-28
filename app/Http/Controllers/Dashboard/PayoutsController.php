<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\StripeConnectedAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Admin-only payouts surface (PAYMENTS Session 4). Read-only by design
 * per locked roadmap decision #24: surfaces balance + last 10 payouts +
 * payout schedule + connected-account health, plus a one-click mint for
 * the Stripe Express dashboard login link. NO payout initiation, schedule
 * change, or pause from riservo.
 *
 * Tenant scoping per locked decision #45: every read goes through
 * `tenant()->business()->stripeConnectedAccount`; the controller never
 * accepts an inbound business id, so cross-tenant access is impossible
 * by construction.
 *
 * Caching: balance + payouts.list + accounts.retrieve + tax.settings.retrieve
 * are wrapped in `Cache::remember` keyed by `payouts:business:{id}` with a
 * 60-second TTL (only successful fetches populate the cache; failures fall
 * back to the prior cached payload with a `stale: true` flag, never poison
 * the cache).
 */
class PayoutsController extends Controller
{
    /**
     * Freshness window — within this window the cached payload is returned
     * as-is and no Stripe calls are made.
     */
    private const FRESHNESS_SECONDS = 60;

    /**
     * Cache TTL — kept long so a fresh fetch always has a fallback to fall
     * back to when Stripe is briefly unreachable. The 60s freshness check
     * inside the payload (via `fetched_at`) is what controls "do we re-hit
     * Stripe?", NOT the cache TTL itself.
     */
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly StripeClient $stripe) {}

    public function index(Request $request): Response
    {
        $business = tenant()->business();
        abort_if($business === null, 404);

        $business->loadMissing('stripeConnectedAccount');
        $row = $business->stripeConnectedAccount;

        $supportedCountries = (array) config('payments.supported_countries');

        // No connected account row: render the onboarding CTA.
        if ($row === null) {
            return Inertia::render('dashboard/payouts', [
                'account' => null,
                'payouts' => null,
                'supportedCountries' => $supportedCountries,
            ]);
        }

        $accountPayload = $this->accountPayload($row);

        // Disabled by Stripe: render the disabled-state panel and skip the
        // Stripe API calls entirely (the row already carries the disabled
        // reason; calling balance/payouts on a disabled account would just
        // surface a Stripe error).
        if ($row->verificationStatus() === 'disabled') {
            return Inertia::render('dashboard/payouts', [
                'account' => $accountPayload,
                'payouts' => null,
                'supportedCountries' => $supportedCountries,
            ]);
        }

        // Pending / incomplete: account is not yet verified. The page renders
        // a "finish onboarding" prompt instead of trying to fetch payout data
        // for an account that can't yet receive any.
        if (in_array($row->verificationStatus(), ['pending', 'incomplete'], true)) {
            return Inertia::render('dashboard/payouts', [
                'account' => $accountPayload,
                'payouts' => null,
                'supportedCountries' => $supportedCountries,
            ]);
        }

        // Active or unsupported_market: surface live payout state. The
        // unsupported_market case still renders the cards (read-only data is
        // harmless); the React page also shows the non-CH banner.
        $payoutsPayload = $this->fetchPayoutsPayload($business->id, $row);

        return Inertia::render('dashboard/payouts', [
            'account' => $accountPayload,
            'payouts' => $payoutsPayload,
            'supportedCountries' => $supportedCountries,
        ]);
    }

    /**
     * Mint a fresh single-use Stripe Express dashboard login link and return
     * its URL as JSON. The frontend opens the URL in a new tab via
     * `window.open(url, '_blank', 'noopener')` (per `resources/js/CLAUDE.md`'s
     * `useHttp` rule); pre-minting on `index()` would burn a link the admin
     * might never click and Stripe expires login links within seconds.
     *
     * Sits inside `billing.writable` (POST) so a SaaS-lapsed admin cannot
     * mint a fresh dashboard surface — consistent with D-116's "the gate is
     * at the mutation edge" stance.
     */
    public function loginLink(Request $request): JsonResponse
    {
        $business = tenant()->business();
        abort_if($business === null, 404);

        $row = $business->stripeConnectedAccount;
        abort_if($row === null, 404);
        abort_if($row->verificationStatus() === 'disabled', 422);

        try {
            $link = $this->stripe->accounts->createLoginLink($row->stripe_account_id);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'error' => __('Could not open Stripe right now. Please try again in a moment.'),
            ], 502);
        }

        return response()->json(['url' => $link->url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPayoutsPayload(int $businessId, StripeConnectedAccount $row): array
    {
        $cacheKey = self::cacheKey($businessId, (string) $row->stripe_account_id);
        $cached = Cache::get($cacheKey);

        // Fresh-cache short-circuit: if the cached payload is younger than
        // FRESHNESS_SECONDS, return it as-is — no Stripe calls. The cache
        // TTL itself is much longer (24h) so a brief Stripe outage past
        // the freshness window still has a fallback to surface as `stale`.
        if (is_array($cached) && $this->isFresh($cached)) {
            return $cached;
        }

        try {
            $payload = $this->fetchFromStripe($row);
            Cache::put($cacheKey, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

            return $payload;
        } catch (ApiErrorException $e) {
            report($e);

            if (is_array($cached)) {
                return array_merge($cached, ['stale' => true]);
            }

            return [
                'available' => [],
                'pending' => [],
                'payouts' => [],
                'schedule' => null,
                'tax_status' => null,
                'fetched_at' => null,
                'stale' => true,
                'error' => 'unreachable',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isFresh(array $payload): bool
    {
        $fetchedAt = $payload['fetched_at'] ?? null;
        if (! is_string($fetchedAt)) {
            return false;
        }
        $timestamp = strtotime($fetchedAt);
        if ($timestamp === false) {
            return false;
        }

        return $timestamp > now()->subSeconds(self::FRESHNESS_SECONDS)->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    private function fetchFromStripe(StripeConnectedAccount $row): array
    {
        $accountId = $row->stripe_account_id;
        $opts = ['stripe_account' => $accountId];

        $balance = $this->stripe->balance->retrieve(null, $opts);
        $payouts = $this->stripe->payouts->all(['limit' => 10], $opts);
        $account = $this->stripe->accounts->retrieve($accountId);
        $taxSettings = $this->stripe->tax->settings->retrieve(null, $opts);

        return [
            'available' => $this->mapBalanceArms($balance->available ?? []),
            'pending' => $this->mapBalanceArms($balance->pending ?? []),
            'payouts' => $this->mapPayouts($payouts->data ?? []),
            'schedule' => $this->mapSchedule($account->settings->payouts->schedule ?? null),
            'tax_status' => $taxSettings->status ?? null,
            'fetched_at' => now()->toIso8601String(),
            'stale' => false,
            'error' => null,
        ];
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $arms
     * @return list<array{amount: int, currency: string}>
     */
    private function mapBalanceArms(iterable $arms): array
    {
        $rows = [];
        foreach ($arms as $arm) {
            $amount = is_object($arm) ? ($arm->amount ?? 0) : ($arm['amount'] ?? 0);
            $currency = is_object($arm) ? ($arm->currency ?? '') : ($arm['currency'] ?? '');
            $rows[] = ['amount' => (int) $amount, 'currency' => (string) $currency];
        }

        return $rows;
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $payouts
     * @return list<array{id: string, amount: int, currency: string, status: string, arrival_date: int|null, created_at: int}>
     */
    private function mapPayouts(iterable $payouts): array
    {
        $rows = [];
        foreach ($payouts as $payout) {
            $rows[] = [
                'id' => (string) ($this->pluck($payout, 'id') ?? ''),
                'amount' => (int) ($this->pluck($payout, 'amount') ?? 0),
                'currency' => (string) ($this->pluck($payout, 'currency') ?? ''),
                'status' => (string) ($this->pluck($payout, 'status') ?? ''),
                'arrival_date' => $this->pluck($payout, 'arrival_date') !== null
                    ? (int) $this->pluck($payout, 'arrival_date')
                    : null,
                'created_at' => (int) ($this->pluck($payout, 'created') ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array{interval: string, delay_days: int|null, weekly_anchor: string|null, monthly_anchor: int|null}|null
     */
    private function mapSchedule(mixed $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }

        return [
            'interval' => (string) ($this->pluck($schedule, 'interval') ?? 'manual'),
            'delay_days' => $this->pluck($schedule, 'delay_days') !== null
                ? (int) $this->pluck($schedule, 'delay_days')
                : null,
            'weekly_anchor' => $this->pluck($schedule, 'weekly_anchor') !== null
                ? (string) $this->pluck($schedule, 'weekly_anchor')
                : null,
            'monthly_anchor' => $this->pluck($schedule, 'monthly_anchor') !== null
                ? (int) $this->pluck($schedule, 'monthly_anchor')
                : null,
        ];
    }

    private function pluck(mixed $source, string $key): mixed
    {
        if (is_object($source)) {
            return $source->{$key} ?? null;
        }

        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(StripeConnectedAccount $row): array
    {
        return [
            'status' => $row->verificationStatus(),
            'country' => $row->country,
            'defaultCurrency' => $row->default_currency,
            'chargesEnabled' => $row->charges_enabled,
            'payoutsEnabled' => $row->payouts_enabled,
            'detailsSubmitted' => $row->details_submitted,
            'requirementsCurrentlyDue' => $row->requirements_currently_due ?? [],
            'requirementsDisabledReason' => $row->requirements_disabled_reason,
            'stripeAccountIdLast4' => substr($row->stripe_account_id, -4),
        ];
    }

    /**
     * F-006 (PAYMENTS Hardening Round 1): cache key includes `stripe_account_id`
     * so a disconnect+reconnect mints a new key and cannot collide with the
     * stale row's cached payload. Disconnect / deauth handlers also forget the
     * key explicitly.
     */
    public static function cacheKey(int $businessId, string $stripeAccountId): string
    {
        return "payouts:business:{$businessId}:account:{$stripeAccountId}";
    }
}
