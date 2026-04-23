<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Services\Payments\CheckoutPromoter;
use App\Support\Billing\DedupesStripeWebhookEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe Connect webhook endpoint (locked roadmap decision #38, D-109).
 *
 * This controller is NOT a Cashier subclass. Cashier's base
 * `WebhookController` routes platform-subscription events
 * (`customer.subscription.*`, `invoice.*`) that do not match the Connect
 * event set (`account.*`, `charge.dispute.*`, `checkout.session.*`,
 * `payment_intent.*`, `charge.refunded`, …). PAYMENTS Sessions 2a / 2b / 3
 * add their handlers to this file; Session 1 ships only `account.*` plus a
 * log-and-200 stub for `charge.dispute.*` so operators can configure the
 * Stripe dashboard subscription list once and not churn it.
 *
 * Signature verification reads `config('services.stripe.connect_webhook_secret')`
 * — distinct from the platform-subscription secret (Cashier's
 * STRIPE_WEBHOOK_SECRET, consumed by `StripeWebhookController` via its
 * `VerifyWebhookSignature` middleware). Setting the config secret to `null`
 * skips verification, mirroring the MVPC-3 test escape hatch.
 *
 * Idempotency: shared D-092 cache-dedup via the `DedupesStripeWebhookEvents`
 * trait with prefix `stripe:connect:event:` (locked roadmap decision #38, D-110).
 * The subscription webhook uses `stripe:subscription:event:`; the two
 * namespaces cannot collide even if the same event id somehow rides both.
 *
 * Outcome-level idempotency (locked roadmap decision #33) is enforced inside
 * each handler via `StripeConnectedAccount::matchesAuthoritativeState()`
 * rather than relying solely on the cache-layer dedup.
 */
class StripeConnectWebhookController
{
    use DedupesStripeWebhookEvents;

    private const DEDUP_PREFIX = 'stripe:connect:event:';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CheckoutPromoter $checkoutPromoter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $event = $this->resolveEvent($request);

        if ($event === null) {
            return new Response('Invalid signature.', 400);
        }

        return $this->dedupedProcess(
            $event->id,
            self::DEDUP_PREFIX,
            fn () => $this->dispatch($event),
        );
    }

    private function resolveEvent(Request $request): ?StripeEvent
    {
        $secret = config('services.stripe.connect_webhook_secret');

        if (! is_string($secret) || $secret === '') {
            // Codex Round-1 finding (D-120): the empty-secret bypass is a
            // testing-only escape hatch. In any non-testing environment a
            // missing/blank STRIPE_CONNECT_WEBHOOK_SECRET turns this endpoint
            // into an unauthenticated state-mutating surface — anyone who
            // knows a real `acct_…` id could POST `account.application.
            // deauthorized` and force `payment_mode = offline`. Fail closed.
            if (! app()->environment('testing')) {
                Log::critical('Stripe Connect webhook received but services.stripe.connect_webhook_secret is not configured — refusing to process', [
                    'environment' => app()->environment(),
                ]);

                return null;
            }

            $payload = json_decode($request->getContent(), true);
            if (! is_array($payload) || ! isset($payload['id'], $payload['type'])) {
                return null;
            }

            return StripeEvent::constructFrom($payload);
        }

        try {
            return StripeWebhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
                tolerance: 300,
            );
        } catch (SignatureVerificationException) {
            return null;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    private function dispatch(StripeEvent $event): Response
    {
        return match ($event->type) {
            'account.updated' => $this->handleAccountUpdated($event),
            'account.application.deauthorized' => $this->handleAccountDeauthorized($event),
            'charge.dispute.created',
            'charge.dispute.updated',
            'charge.dispute.closed' => $this->handleDisputeEvent($event),
            // PAYMENTS Session 2a: happy-path promotion. Both event names
            // flow through the same handler because locked decision #41
            // branches on `$session->payment_status`, NOT on event name —
            // so a future Stripe flip of TWINT to async cannot regress
            // the promotion path.
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($event),
            default => new Response('Webhook unhandled.', 200),
        };
    }

    /**
     * Locked roadmap decision #34: treat the payload as a nudge and re-fetch
     * authoritative state via `stripe.accounts.retrieve()`. Out-of-order
     * delivery of two `account.updated` events therefore converges to
     * whatever Stripe currently reports.
     *
     * Codex Round-4 finding (D-128): the row save AND the business
     * `payment_mode` demotion happen in one DB transaction — partial failure
     * (row saved, business save crashed) used to leave the system in a
     * forever-broken state, because the next Stripe retry would see the row
     * matching authoritative state and 200-noop without ever fixing the
     * business. We now (a) wrap both writes in a transaction so they fail
     * atomically, and (b) ALWAYS evaluate the demotion check before the
     * matches() short-circuit, so a retry after a partial-success crash
     * still finishes the demotion.
     */
    private function handleAccountUpdated(StripeEvent $event): Response
    {
        $accountId = $this->extractAccountId($event);

        if ($accountId === null) {
            return new Response('Missing account id.', 200);
        }

        // Codex Round-12 (D-149): if the row is missing in BOTH active and
        // trashed states, the event has most likely raced our
        // `accounts.create` + local insert transaction — Stripe emitted
        // `account.updated` after returning the `acct_…` but before our
        // transaction committed, so the row isn't visible to readers yet.
        // Returning 200 would let the D-092 cache dedupe future retries
        // and permanently strand us on whatever snapshot `create()` wrote.
        // Return 503 so Stripe retries; the dedup trait skips caching on
        // non-2xx, which is exactly the contract we need here. A genuinely
        // orphan `acct_…` (no local row ever) produces infinite retries
        // with alerts on the critical log — a noisy-but-correct outcome
        // versus a silent-but-wrong one.
        if (! StripeConnectedAccount::withTrashed()->where('stripe_account_id', $accountId)->exists()) {
            Log::warning('Connect account.updated received for unknown stripe_account_id — returning 503 to force Stripe retry', [
                'stripe_account_id' => $accountId,
                'event_id' => $event->id,
            ]);

            return new Response('Unknown account — retry.', 503, ['Retry-After' => '60']);
        }

        // Codex Round-7 finding (D-136): two concurrent `account.updated`
        // deliveries used to race — request A could fetch an older
        // `incomplete` snapshot, request B could fetch a newer `active`
        // snapshot, B commits first, A commits last and regresses the row.
        // Fix: serialise per-account processing via `lockForUpdate` on the
        // row BEFORE calling `accounts.retrieve`. While B waits on the
        // lock, A's transaction completes; by the time B acquires the
        // lock, its `accounts.retrieve` sees the current Stripe state
        // (never older than what A committed, because Stripe's server-
        // side monotonic state advances strictly forward between the two
        // API calls). Holding the lock across a Stripe API call is
        // acceptable for this webhook's traffic profile (<1 req/s per
        // account); if it becomes a hotspot, swap to an advisory-lock or
        // event-sequence-based serialisation.
        // Codex Round-12 (D-149): pull the row inside the lock with
        // `withTrashed()` so a concurrent Disconnect that already trashed
        // the row between the pre-check (which also uses `withTrashed`)
        // and this lock still resolves to a row — we just no-op on the
        // update. Returning here without a sync is correct: the trashed
        // row reflects the disconnect decision; a Stripe-side capability
        // drift against a disconnected account is irrelevant.
        DB::transaction(function () use ($accountId, $event) {
            $row = StripeConnectedAccount::withTrashed()
                ->where('stripe_account_id', $accountId)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->trashed()) {
                Log::info('account.updated race: row trashed or gone by the time we acquired the lock', [
                    'stripe_account_id' => $accountId,
                    'event_id' => $event->id,
                    'trashed' => $row !== null,
                ]);

                return;
            }

            $fresh = $this->stripe->accounts->retrieve($accountId);

            $fields = [
                'country' => (string) $fresh->country,
                'charges_enabled' => (bool) $fresh->charges_enabled,
                'payouts_enabled' => (bool) $fresh->payouts_enabled,
                'details_submitted' => (bool) $fresh->details_submitted,
                'requirements_currently_due' => $fresh->requirements->currently_due ?? [],
                'requirements_disabled_reason' => $fresh->requirements->disabled_reason ?? null,
                'default_currency' => $fresh->default_currency,
            ];

            if (! $row->matchesAuthoritativeState($fields)) {
                $row->fill($fields)->save();
            }

            // Locked roadmap decision #20: a Stripe-side capability loss
            // (card payments disabled, payouts frozen, KYC rejected) forces
            // payment_mode back to offline. D-128 (Round 4): this check
            // runs even when the row matches Stripe so a retry after a
            // partial-success crash still finishes the demotion.
            $business = $row->business;
            if ($business !== null
                && ! $business->canAcceptOnlinePayments()
                && $business->payment_mode !== PaymentMode::Offline) {
                $business->forceFill(['payment_mode' => PaymentMode::Offline->value])->save();
            }
        });

        return new Response('OK', 200);
    }

    /**
     * Locked roadmap decision #21 + #36: treat identically to the dashboard
     * disconnect action — soft-delete the row (retaining stripe_account_id for
     * 2b's late-webhook refund path) and force payment_mode to offline.
     *
     * Codex Round-4 finding (D-128): the lookup uses `withTrashed()` so a
     * retry after a partial-success crash (row soft-deleted, business save
     * failed) still finds the row and finishes demoting the business. The
     * row delete + business demotion are wrapped in a single transaction so
     * partial failure cannot recur.
     */
    private function handleAccountDeauthorized(StripeEvent $event): Response
    {
        $accountId = $this->extractAccountId($event);

        if ($accountId === null) {
            return new Response('Missing account id.', 200);
        }

        $row = StripeConnectedAccount::withTrashed()
            ->where('stripe_account_id', $accountId)
            ->first();

        if ($row === null) {
            // Codex Round-12 (D-149): same retry-not-dedupe treatment as
            // `account.updated`. A missing row on deauthorize is almost
            // certainly the create-then-deauthorize-in-the-first-seconds
            // race — return 503 and let Stripe retry until the local row
            // appears. The dedup trait doesn't cache non-2xx responses.
            Log::warning('Connect account.application.deauthorized for unknown stripe_account_id — returning 503 to force Stripe retry', [
                'stripe_account_id' => $accountId,
                'event_id' => $event->id,
            ]);

            return new Response('Unknown account — retry.', 503, ['Retry-After' => '60']);
        }

        DB::transaction(function () use ($row) {
            if (! $row->trashed()) {
                $row->delete();
            }

            $business = $row->business;
            if ($business === null) {
                return;
            }

            // Codex Round-8 finding (D-139): a late deauthorize for a retired
            // `acct_…` must NOT demote a business that has already reconnected
            // with a fresh active connected account. After trashing `$row`,
            // check whether an OTHER active row remains for this business
            // (the default `stripeConnectedAccount` relation is SoftDeletes-
            // scoped, so it returns only the non-trashed one). Only when
            // there is no active account left — either because this row was
            // the active one and we just trashed it, or because the row was
            // already trashed and no reconnect happened — do we force
            // `payment_mode = offline`. Historical soft-deleted rows never
            // mutate current business state.
            $business->unsetRelation('stripeConnectedAccount');
            $activeRow = $business->stripeConnectedAccount;

            if ($activeRow !== null) {
                return; // business has reconnected with a fresh account; don't touch.
            }

            if ($business->payment_mode !== PaymentMode::Offline) {
                $business->forceFill(['payment_mode' => PaymentMode::Offline->value])->save();
            }
        });

        return new Response('OK', 200);
    }

    /**
     * Codex Round-2 finding (D-123): the Session 1 stub used to be a pure
     * log-and-200, which combined with the dedup cache silently swallowed
     * any real dispute that fired before Session 3's full pipeline (locked
     * roadmap decision #35) shipped. We now persist the dispute as a
     * `payment.dispute_opened` Pending Action so no data is lost.
     *
     * Session 1 scope:
     *  - upsert one Pending Action row per (business, dispute_id) on
     *    `charge.dispute.created` / `charge.dispute.updated`;
     *  - resolve the row (status = resolved) on `charge.dispute.closed`,
     *    capturing the dispute outcome in `resolution_note`.
     *
     * Session 3 will:
     *  - email Business admins on `created` (locked decision #35);
     *  - deep-link the row to the Stripe Express dispute UI in the
     *    dashboard panel;
     *  - backfill `booking_id` once Session 2a's
     *    `bookings.stripe_charge_id` column lands.
     *
     * For dispute events the connected account id rides on `event.account`
     * (not `event.data.object.id`, which is the dispute id `dp_…`). The
     * connected-account lookup includes soft-deleted rows so a dispute that
     * fires after a Disconnect still resolves to the original Business.
     */
    private function handleDisputeEvent(StripeEvent $event): Response
    {
        $accountId = $event->account ?? null;
        if (! is_string($accountId) || $accountId === '') {
            Log::warning('Connect dispute event missing event.account', ['event_id' => $event->id]);

            return new Response('Missing account.', 200);
        }

        $row = StripeConnectedAccount::withTrashed()
            ->where('stripe_account_id', $accountId)
            ->first();

        if ($row === null) {
            Log::critical('Connect dispute event for unknown stripe_account_id — manual reconciliation required', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'stripe_account_id' => $accountId,
            ]);

            return new Response('Unknown account.', 200);
        }

        $dispute = $event->data->object ?? null;
        $disputeId = is_object($dispute) && is_string($dispute->id ?? null) ? $dispute->id : null;

        if ($disputeId === null) {
            Log::warning('Connect dispute event missing dispute id', ['event_id' => $event->id]);

            return new Response('Missing dispute id.', 200);
        }

        $payload = [
            'dispute_id' => $disputeId,
            'charge_id' => $dispute->charge ?? null,
            'amount' => $dispute->amount ?? null,
            'currency' => $dispute->currency ?? null,
            'reason' => $dispute->reason ?? null,
            'status' => $dispute->status ?? null,
            'evidence_due_by' => $dispute->evidence_details->due_by ?? null,
            'last_event_id' => $event->id,
            'last_event_type' => $event->type,
        ];

        // Codex Round-3 finding (D-126): the upsert MUST be race-safe under
        // concurrent dispute event delivery. The partial unique index on
        // `payload->>'dispute_id' WHERE type = 'payment.dispute_opened'` is
        // the DB-enforced invariant; this code path either inserts (winner)
        // or catches `UniqueConstraintViolationException` and re-reads the
        // row (loser) → both branches converge on the existing row for the
        // update.
        $closing = $event->type === 'charge.dispute.closed';
        $isDisputeClosed = $dispute->status ?? null;

        $insertAttributes = [
            'business_id' => $row->business_id,
            'type' => PendingActionType::PaymentDisputeOpened,
            'payload' => $payload,
            'status' => $closing ? PendingActionStatus::Resolved : PendingActionStatus::Pending,
            'resolution_note' => $closing ? 'closed:'.($isDisputeClosed ?? 'unknown') : null,
            'resolved_at' => $closing ? now() : null,
        ];

        // Wrap the insert in a transaction (Postgres SAVEPOINT when nested,
        // standard transaction otherwise) so a unique-violation only rolls
        // back the insert attempt — the outer request / test transaction
        // stays alive and the catch block can re-read the existing row.
        // Without the savepoint, Postgres aborts the entire transaction on
        // the unique error and every subsequent query in this request
        // fails with `current transaction is aborted`.
        try {
            DB::transaction(static fn () => PendingAction::create($insertAttributes));

            return new Response('OK', 200);
        } catch (UniqueConstraintViolationException) {
            // Race-loser path: another request inserted first (or the test
            // pre-seeded a row). Re-read and update; same path as the
            // existing-row branch below.
        }

        $existing = PendingAction::where('business_id', $row->business_id)
            ->where('type', PendingActionType::PaymentDisputeOpened->value)
            ->where('payload->dispute_id', $disputeId)
            ->first();

        // Defensive: the row genuinely should exist after a unique-violation,
        // but guard against the race-against-deletion case.
        if ($existing === null) {
            return new Response('OK', 200);
        }

        if ($closing) {
            $existing->forceFill([
                'payload' => $payload,
                'status' => PendingActionStatus::Resolved,
                'resolution_note' => 'closed:'.($isDisputeClosed ?? 'unknown'),
                'resolved_at' => now(),
            ])->save();

            return new Response('OK', 200);
        }

        // Outcome-level idempotency: if the existing row is already resolved
        // (closed event arrived first), don't reopen on a stale created /
        // updated replay.
        if ($existing->status === PendingActionStatus::Resolved) {
            return new Response('OK', 200);
        }

        $existing->forceFill(['payload' => $payload])->save();

        return new Response('OK', 200);
    }

    /**
     * PAYMENTS Session 2a happy-path handler.
     *
     * Stripe emits `checkout.session.completed` as soon as the hosted page
     * transitions to terminal (paid OR async-pending). For synchronous
     * methods (card, TWINT today) the session's `payment_status` is already
     * `'paid'` at this event. For async methods, `payment_status` is not
     * `'paid'` here; Stripe follows up with
     * `checkout.session.async_payment_succeeded` carrying a paid session.
     * Locked decision #41 binds us to branch on `$session->payment_status`,
     * not on event name — which is exactly what `CheckoutPromoter` does.
     *
     * Outcome-level idempotency (locked decision #33): the promoter's
     * `lockForUpdate`-inside-transaction + DB-state guard collapse
     * replays / inline-race scenarios to a no-op without double-notifying.
     * The cache-layer event-id dedup (D-092 / D-110) is belt-and-braces.
     *
     * Disconnect race safety (locked decision #36): the connected-account
     * lookup uses `withTrashed()` so a late webhook after an admin
     * Disconnect still resolves the business / account pairing — funds
     * were already charged on the connected account, retaining the id on
     * the soft-deleted row is the documented invariant.
     */
    private function handleCheckoutSessionCompleted(StripeEvent $event): Response
    {
        $session = $event->data->object ?? null;

        if (! $session instanceof StripeCheckoutSession) {
            // Defensive: event shape divergence. The Stripe PHP SDK constructs
            // `$session` from the payload, so this should only fire on a
            // malformed body.
            Log::warning('Connect checkout.session.completed: data.object is not a Checkout Session', [
                'event_id' => $event->id,
            ]);

            return new Response('Invalid session payload.', 200);
        }

        $bookingRef = $session->client_reference_id ?? null;
        if (! is_string($bookingRef) || ! ctype_digit($bookingRef)) {
            Log::warning('Connect checkout.session.completed missing or non-numeric client_reference_id', [
                'event_id' => $event->id,
                'session_id' => $session->id ?? null,
            ]);

            return new Response('Missing client_reference_id.', 200);
        }

        $booking = Booking::find((int) $bookingRef);
        if ($booking === null) {
            Log::critical('Connect checkout.session.completed for unknown booking id — manual reconciliation required', [
                'event_id' => $event->id,
                'session_id' => $session->id ?? null,
                'client_reference_id' => $bookingRef,
            ]);

            return new Response('Unknown booking.', 200);
        }

        // Cross-account guard: the session's `account` must match the
        // CONNECTED ACCOUNT THAT MINTED this booking's Checkout session,
        // not the business's current active row. Codex Round 2 (D-158):
        // reading the id off the booking is deterministic across
        // disconnect+reconnect cycles; reading it off the business via
        // `withTrashed()->value()` was non-deterministic (multiple historical
        // rows for the same business). Fail closed if the booking lacks
        // the pinned id — every online booking created via `store`'s M2
        // branch writes it, so a null here is a pre-migration / data-drift
        // anomaly worth surfacing rather than silently falling back.
        $expectedAccountId = $booking->stripe_connected_account_id;
        $sessionAccountId = $session->account ?? ($event->account ?? null);

        if (! is_string($expectedAccountId) || $expectedAccountId === '' || $sessionAccountId !== $expectedAccountId) {
            Log::critical('Connect checkout.session.completed cross-account mismatch — refusing to promote', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
                'session_account' => $sessionAccountId,
                'expected_account' => $expectedAccountId,
            ]);

            return new Response('Cross-account mismatch.', 200);
        }

        // Outcome-level fast path before touching the promoter: if the
        // booking is already Paid, short-circuit without locking. The
        // promoter re-checks under a lock for race-safety regardless.
        if ($booking->payment_status === PaymentStatus::Paid) {
            return new Response('Already paid.', 200);
        }

        $this->checkoutPromoter->promote($booking, $session);

        return new Response('OK', 200);
    }

    private function extractAccountId(StripeEvent $event): ?string
    {
        // Stripe's `account.updated` delivers the account id on the event
        // itself (event.account) AND on event.data.object.id. We prefer
        // event.data.object.id for parity with `accounts.retrieve`; fall back
        // to event.account for the deauthorized variant which carries only
        // the top-level account id.
        $data = $event->data->object ?? null;

        if (is_object($data) && is_string($data->id ?? null)) {
            return $data->id;
        }

        return $event->account ?? null;
    }
}
