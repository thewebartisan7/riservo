<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\BookingRefundStatus;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingReceivedNotification;
use App\Notifications\Payments\CancelledAfterPaymentNotification;
use App\Notifications\Payments\DisputeClosedNotification;
use App\Notifications\Payments\DisputeOpenedNotification;
use App\Services\Payments\CheckoutPromoter;
use App\Services\Payments\RefundResult;
use App\Services\Payments\RefundService;
use App\Support\Billing\DedupesStripeWebhookEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Charge as StripeCharge;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Refund as StripeRefund;
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
        private readonly RefundService $refundService,
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
            // PAYMENTS Session 2b: failure-branching. `online` bookings get
            // cancelled (slot released via GIST); `customer_choice` bookings
            // are promoted to `Confirmed + Unpaid` (or `Pending + Unpaid`
            // under manual-confirm per locked decision #29) — slot stays
            // held. Locked decision #14 binds the branching.
            //
            // Codex Round 1 (F1): `payment_intent.*` arms were dropped.
            // Bookings persist `stripe_payment_intent_id` only at promotion
            // time (Session 2a happy-path handler or `applyLateWebhookRefund`
            // below), so resolving a booking from a PI id in the failure
            // or late-success case would return null. The two Checkout-
            // level events below cover every case we handle today — both
            // carry `client_reference_id` and resolve deterministically.
            // The late-webhook refund path is still live via the
            // Cancelled-branch inside `handleCheckoutSessionCompleted`.
            'checkout.session.expired',
            'checkout.session.async_payment_failed' => $this->handleCheckoutSessionFailed($event),
            // PAYMENTS Session 3 — refund-settlement webhook arms. `charge.
            // refunded` delivers a Charge whose `refunds->data` carries the
            // refund objects; the two `.refund.updated` variants deliver a
            // Refund directly. D-171: match rows via stripe_refund_id.
            'charge.refunded',
            'charge.refund.updated',
            'refund.updated' => $this->handleRefundEvent($event),
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

        // PAYMENTS Session 3: try to resolve the dispute's charge back to a
        // booking via `stripe_payment_intent_id`. Stripe disputes carry a
        // `payment_intent` field on the Dispute object. When found, the PA
        // gets linked via `booking_id`. When null (rare — e.g. a dispute
        // on a charge whose booking was deleted), the PA still lands so
        // admins can investigate in Stripe.
        $linkedBooking = null;
        $paymentIntentId = is_string($dispute->payment_intent ?? null) ? $dispute->payment_intent : null;
        if ($paymentIntentId !== null) {
            $linkedBooking = Booking::where('stripe_payment_intent_id', $paymentIntentId)
                ->where('business_id', $row->business_id)
                ->first();
        }

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
            'booking_id' => $linkedBooking?->id,
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
        $wasInserted = false;
        try {
            DB::transaction(static fn () => PendingAction::create($insertAttributes));
            $wasInserted = true;
        } catch (UniqueConstraintViolationException) {
            // Race-loser path: another request inserted first (or the test
            // pre-seeded a row). Re-read and update; same path as the
            // existing-row branch below.
        }

        // PAYMENTS Session 3 (locked decision #35): admin email on the
        // `created` arm. `updated` only refreshes the PA payload; `closed`
        // dispatches `DisputeClosedNotification` carrying the outcome. The
        // cache-layer D-092 dedup collapses exact replays; the PA's "was
        // inserted" bit tells genuine first-deliveries from race-loser
        // re-reads so duplicate emails aren't dispatched on a second run.
        if ($wasInserted) {
            $pa = PendingAction::where('business_id', $row->business_id)
                ->where('type', PendingActionType::PaymentDisputeOpened->value)
                ->where('payload->dispute_id', $disputeId)
                ->first();

            if ($pa !== null) {
                if ($closing) {
                    $this->dispatchDisputeClosedEmail($row->business_id, $linkedBooking, $pa);
                } elseif ($event->type === 'charge.dispute.created') {
                    $this->dispatchDisputeOpenedEmail($row->business_id, $linkedBooking, $pa);
                }
            }

            return new Response('OK', 200);
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
            // Transition from Pending → Resolved is the meaningful one; if
            // the PA is ALREADY Resolved (stale replay), skip the email so
            // admins don't get duplicate closing emails.
            $wasPending = $existing->status === PendingActionStatus::Pending;

            $existing->forceFill([
                'booking_id' => $existing->booking_id ?? $linkedBooking?->id,
                'payload' => $payload,
                'status' => PendingActionStatus::Resolved,
                'resolution_note' => 'closed:'.($isDisputeClosed ?? 'unknown'),
                'resolved_at' => now(),
            ])->save();

            if ($wasPending) {
                $this->dispatchDisputeClosedEmail($row->business_id, $linkedBooking, $existing);
            }

            return new Response('OK', 200);
        }

        // Outcome-level idempotency: if the existing row is already resolved
        // (closed event arrived first), don't reopen on a stale created /
        // updated replay.
        if ($existing->status === PendingActionStatus::Resolved) {
            return new Response('OK', 200);
        }

        $existing->forceFill([
            'booking_id' => $existing->booking_id ?? $linkedBooking?->id,
            'payload' => $payload,
        ])->save();

        // `updated` arms refresh without emailing. `created` that lands on
        // an existing Pending row is a race replay — cache-layer dedup will
        // already have caught same-id replays; skip email.
        return new Response('OK', 200);
    }

    /**
     * Admin email for `charge.dispute.created` (locked decision #35).
     * Admin-only per locked decisions #19 / #35 — staff do not receive
     * dispute emails.
     */
    private function dispatchDisputeOpenedEmail(int $businessId, ?Booking $booking, PendingAction $pa): void
    {
        $admins = $this->businessAdmins($businessId);
        if ($admins->isEmpty()) {
            return;
        }
        Notification::send($admins, new DisputeOpenedNotification($booking, $pa));
    }

    /**
     * Admin email for `charge.dispute.closed` carrying the final outcome
     * (won / lost / warning_closed).
     */
    private function dispatchDisputeClosedEmail(int $businessId, ?Booking $booking, PendingAction $pa): void
    {
        $admins = $this->businessAdmins($businessId);
        if ($admins->isEmpty()) {
            return;
        }
        Notification::send($admins, new DisputeClosedNotification($booking, $pa));
    }

    /**
     * @return Collection<int, User>
     */
    private function businessAdmins(int $businessId): Collection
    {
        $business = Business::find($businessId);
        if ($business === null) {
            return collect();
        }
        $business->loadMissing('admins');

        return $business->admins;
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

        // PAYMENTS Session 2b: late-webhook refund path (locked decision
        // #31.3). If the reaper already cancelled this booking but the
        // Checkout session eventually completed, route to the refund
        // branch — `CheckoutPromoter::promote` would otherwise reject via
        // its session-id / DB-state guard (booking is Cancelled + not
        // AwaitingPayment) which surfaces as a misleading `'mismatch'`
        // critical log.
        //
        // Codex Round 2 (F2): the Cancelled-branch check runs BEFORE the
        // generic "already paid" short-circuit. On a retry after a
        // transient Stripe error during `RefundService::refund`, the
        // booking is `Cancelled + Paid` with a `booking_refunds` row
        // sitting at `pending`; `applyLateWebhookRefund` plus the
        // service's pending-row-reuse path will retry the refund with the
        // same UUID so Stripe's idempotency key collapses the duplicate.
        //
        // Codex Round 4 (F2): the happy-path promotion routes through
        // `CheckoutPromoter::promote` which enforces the D-156 session-id
        // match. The Cancelled-branch bypasses the promoter, so it must
        // enforce the same trust boundary here — a cross-session-id
        // `client_reference_id` collision on the same connected account
        // could otherwise mark the wrong cancelled booking Paid + refund.
        if ($booking->status === BookingStatus::Cancelled) {
            $sessionId = is_string($session->id ?? null) ? $session->id : null;
            if ($sessionId === null
                || $booking->stripe_checkout_session_id === null
                || $sessionId !== $booking->stripe_checkout_session_id) {
                Log::critical('Connect checkout.session.completed (Cancelled-branch): session id mismatch — refusing to refund', [
                    'event_id' => $event->id,
                    'booking_id' => $booking->id,
                    'session_id' => $sessionId,
                    'expected_session_id' => $booking->stripe_checkout_session_id,
                ]);

                return new Response('Session id mismatch.', 200);
            }

            return $this->applyLateWebhookRefund(
                $booking,
                $session->payment_intent,
                $session->amount_total,
                $session->currency,
                $session->latest_charge ?? null,
                $event->id,
            );
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

    /**
     * PAYMENTS Session 2b: Checkout-side failure events for the same booking
     * shape the happy-path handler saw — `checkout.session.expired` and
     * `checkout.session.async_payment_failed`.
     *
     * Branching on `payment_mode_at_creation` per locked decision #14:
     *  - `'online'` → Cancel the booking (slot releases via the GIST
     *     exclusion on status IN (pending, confirmed)). No notifications.
     *  - `'customer_choice'` → Promote to `Confirmed + Unpaid` (or
     *     `Pending + Unpaid` under manual-confirm per locked decision #29).
     *     Slot stays held; the customer pays at the appointment. Standard
     *     booking-confirmed notifications fire (or the pending-awaiting-
     *     confirmation variant for manual).
     *  - `'offline'` → defensive: a Checkout session should never exist for
     *     an offline-at-creation booking. Log critical + 200.
     */
    private function handleCheckoutSessionFailed(StripeEvent $event): Response
    {
        $session = $event->data->object ?? null;

        if (! $session instanceof StripeCheckoutSession) {
            Log::warning('Connect checkout.session failure: data.object is not a Checkout Session', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return new Response('Invalid session payload.', 200);
        }

        $bookingRef = $session->client_reference_id ?? null;
        if (! is_string($bookingRef) || ! ctype_digit($bookingRef)) {
            Log::warning('Connect checkout.session failure missing or non-numeric client_reference_id', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'session_id' => $session->id ?? null,
            ]);

            return new Response('Missing client_reference_id.', 200);
        }

        $booking = Booking::find((int) $bookingRef);
        if ($booking === null) {
            Log::critical('Connect checkout.session failure for unknown booking id — manual reconciliation required', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'session_id' => $session->id ?? null,
                'client_reference_id' => $bookingRef,
            ]);

            return new Response('Unknown booking.', 200);
        }

        // D-158 cross-account guard mirrors the happy-path handler.
        $expectedAccountId = $booking->stripe_connected_account_id;
        $sessionAccountId = $session->account ?? ($event->account ?? null);

        if (! is_string($expectedAccountId) || $expectedAccountId === '' || $sessionAccountId !== $expectedAccountId) {
            Log::critical('Connect checkout.session failure cross-account mismatch — refusing to act', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'booking_id' => $booking->id,
                'session_account' => $sessionAccountId,
                'expected_account' => $expectedAccountId,
            ]);

            return new Response('Cross-account mismatch.', 200);
        }

        // Codex Round 4 (F3): session-id trust boundary — mirrors the
        // D-156 guard that `CheckoutPromoter::promote` enforces on the
        // happy-path. An unrelated Checkout session on the same account
        // with a colliding `client_reference_id` could otherwise cancel
        // or promote the wrong booking.
        $sessionId = is_string($session->id ?? null) ? $session->id : null;
        if ($sessionId === null
            || $booking->stripe_checkout_session_id === null
            || $sessionId !== $booking->stripe_checkout_session_id) {
            Log::critical('Connect checkout.session failure: session id mismatch — refusing to act', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'booking_id' => $booking->id,
                'session_id' => $sessionId,
                'expected_session_id' => $booking->stripe_checkout_session_id,
            ]);

            return new Response('Session id mismatch.', 200);
        }

        return $this->applyCheckoutFailureBranch($booking, $event->id);
    }

    /**
     * Shared branch-on-snapshot body for Checkout-side failure events.
     * Outcome-level guards at the top; `DB::transaction + lockForUpdate`
     * around the write; notifications dispatched OUTSIDE the lock per the
     * D-151 shape.
     */
    private function applyCheckoutFailureBranch(Booking $booking, string $eventId): Response
    {
        // Outcome-level idempotency (locked decision #33). Already-paid,
        // already-cancelled, and already-unpaid bookings are all terminal
        // for this flow.
        if ($booking->payment_status === PaymentStatus::Paid) {
            return new Response('Already paid.', 200);
        }

        if ($booking->status === BookingStatus::Cancelled) {
            return new Response('Already cancelled.', 200);
        }

        // Codex Round 4 (F4): `customer_choice` failure branches land the
        // booking at `Confirmed/Pending + Unpaid`. That state is terminal
        // for this flow — a replay (fresh event id after the 24h dedup
        // cache TTL expires) must NOT re-dispatch the customer + staff
        // notifications. The cache-layer dedup catches same-id replays;
        // the outcome-level guard catches fresh-id replays.
        if ($booking->payment_status === PaymentStatus::Unpaid) {
            return new Response('Already unpaid (customer_choice failure handled).', 200);
        }

        $mode = $booking->payment_mode_at_creation;

        if ($mode === 'offline') {
            // Defensive: a Checkout session should never exist for an
            // offline-at-creation booking. Surface critical for operator
            // investigation; do not act.
            Log::critical('Connect Checkout failure for a booking with payment_mode_at_creation=offline — data anomaly', [
                'event_id' => $eventId,
                'booking_id' => $booking->id,
            ]);

            return new Response('Unexpected offline snapshot.', 200);
        }

        $targetStatus = null;
        $targetPaymentStatus = null;
        $promoted = null;

        DB::transaction(function () use ($booking, $mode, &$targetStatus, &$targetPaymentStatus, &$promoted): void {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            // Re-check outcome inside the lock (concurrent reaper cancel or
            // concurrent success-page promotion may have moved the row).
            if ($locked->payment_status === PaymentStatus::Paid) {
                return;
            }

            if ($locked->status === BookingStatus::Cancelled) {
                return;
            }

            if ($mode === 'online') {
                $targetStatus = BookingStatus::Cancelled;
                $targetPaymentStatus = PaymentStatus::NotApplicable;
            } else {
                // customer_choice branch: locked decision #14 + #29.
                $targetStatus = $locked->business->confirmation_mode === ConfirmationMode::Manual
                    ? BookingStatus::Pending
                    : BookingStatus::Confirmed;
                $targetPaymentStatus = PaymentStatus::Unpaid;
            }

            $locked->forceFill([
                'status' => $targetStatus,
                'payment_status' => $targetPaymentStatus,
                'expires_at' => null,
            ])->save();

            $promoted = $locked;
        });

        if ($promoted !== null && $mode === 'customer_choice') {
            $this->dispatchCustomerChoiceFailureNotifications($promoted);
        }

        return new Response('OK', 200);
    }

    /**
     * Locked decision #14 + #29: customer_choice bookings landing at
     * `Confirmed + Unpaid` or `Pending + Unpaid` notify as if they were
     * real bookings (they are — the customer committed to the slot).
     *
     * - Confirmed: customer gets `BookingConfirmedNotification`; staff gets
     *   `BookingReceivedNotification('new')`.
     * - Pending: staff gets `BookingReceivedNotification('new')`; customer
     *   gets `BookingReceivedNotification('pending_unpaid_awaiting_
     *   confirmation')` — distinct from the paid-variant which promises
     *   a refund on rejection, whereas here there's nothing to refund.
     */
    private function dispatchCustomerChoiceFailureNotifications(Booking $booking): void
    {
        $booking->loadMissing(['customer', 'business.admins', 'provider.user', 'service']);

        // Codex Round 3 (F4): a customer_choice booking that lands at
        // `Confirmed + Unpaid` via the failure branch is a real booking
        // from the provider's perspective — the slot is held and the
        // customer will show up to pay. MVPC-2's outbound Google Calendar
        // sync pushes Confirmed bookings on the create path
        // (`PublicBookingController::store`) and on admin-approval
        // (`Dashboard\BookingController::updateStatus`). This path must
        // match — `shouldPushToCalendar()` folds in the integration +
        // source gates (D-083 + D-088). Pending-under-manual-confirm
        // doesn't push here; it'll push on admin approval, same as every
        // other pending booking.
        if ($booking->status === BookingStatus::Confirmed && $booking->shouldPushToCalendar()) {
            PushBookingToCalendarJob::dispatch($booking->id, 'create');
        }

        if ($booking->shouldSuppressCustomerNotifications()) {
            return;
        }

        $customer = $booking->customer;

        if ($booking->status === BookingStatus::Confirmed) {
            if ($customer !== null) {
                Notification::route('mail', $customer->email)
                    ->notify(new BookingConfirmedNotification($booking));
            }
            $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'new'));

            return;
        }

        // Pending + Unpaid: manual-confirm + customer_choice + failed Checkout.
        if ($customer !== null) {
            Notification::route('mail', $customer->email)
                ->notify(new BookingReceivedNotification($booking, 'pending_unpaid_awaiting_confirmation'));
        }
        $this->notifyStaff($booking, new BookingReceivedNotification($booking, 'new'));
    }

    private function notifyStaff(Booking $booking, BookingReceivedNotification $notification): void
    {
        $staffUsers = $booking->business->admins
            ->when($booking->provider?->user, fn ($c) => $c->merge([$booking->provider->user]))
            ->unique('id');

        Notification::send($staffUsers, $notification);
    }

    /**
     * Late-webhook refund body (locked decision #31.3). Called from the
     * Cancelled-branch inside `handleCheckoutSessionCompleted` —
     * `payment_intent.succeeded` is NOT a caller as of Codex Round 1 F1
     * (the PI id is null on bookings pre-promotion, so a PI-keyed lookup
     * cannot resolve a reaper-cancelled booking). The Checkout-level
     * event carries `client_reference_id` and resolves reliably.
     *
     * Steps:
     *  1. `DB::transaction + lockForUpdate`: flip booking to Paid, populate
     *     charge columns. Booking stays `Cancelled` (the slot may be re-
     *     booked).
     *  2. Dispatch `RefundService::refund($booking, null, 'cancelled-after-
     *     payment')` — the service inserts a booking_refunds row with a
     *     UUID idempotency key, calls Stripe, writes the outcome.
     *  3. Upsert a `payment.cancelled_after_payment` Pending Action
     *     (admin-visible).
     *  4. Dispatch `CancelledAfterPaymentNotification` to admins.
     */
    private function applyLateWebhookRefund(
        Booking $booking,
        mixed $paymentIntentId,
        mixed $amountCents,
        mixed $currency,
        mixed $chargeId,
        string $eventId,
    ): Response {
        // Defensive shape checks — Stripe's PHP SDK usually constructs
        // these, but the tolerant webhook-testing path (missing secret)
        // goes through json_decode which loses the type hints.
        $piId = is_string($paymentIntentId) ? $paymentIntentId : null;
        $amount = is_int($amountCents) ? $amountCents : (int) $amountCents;
        $currencyLower = is_string($currency) ? strtolower($currency) : null;
        $chargeIdValue = is_string($chargeId) ? $chargeId : null;

        if ($piId === null || $amount <= 0 || $currencyLower === null) {
            Log::warning('Connect late-webhook refund: insufficient payload to act', [
                'event_id' => $eventId,
                'booking_id' => $booking->id,
                'payment_intent_id' => $paymentIntentId,
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ]);

            return new Response('Insufficient payload.', 200);
        }

        $shouldDispatchRefund = false;

        DB::transaction(function () use ($booking, $piId, $amount, $currencyLower, $chargeIdValue, &$shouldDispatchRefund): void {
            $locked = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($locked->status !== BookingStatus::Cancelled) {
                return;
            }

            // Codex Round 2 (F2): a terminal refund outcome (Refunded,
            // RefundFailed) short-circuits — either success or operator
            // has been notified. But a retry after a TRANSIENT Stripe
            // error lands here with `payment_status = Paid` and a
            // `booking_refunds` row still at `pending`; that path re-
            // dispatches the refund so Stripe's idempotency key (same
            // row UUID) collapses the duplicate.
            if ($locked->payment_status === PaymentStatus::Refunded
                || $locked->payment_status === PaymentStatus::RefundFailed) {
                return;
            }

            if ($locked->payment_status === PaymentStatus::Paid) {
                // Codex Round 4 (F1): dispatch whenever we're not at a
                // terminal refund outcome — NOT only when a pending row
                // already exists. If the process crashed after the
                // Paid-flip transaction committed but before
                // RefundService inserted its row, a replay finds Paid +
                // no row and must still dispatch (the service's
                // retry-path lookup handles both "existing pending row"
                // and "fresh insert" transparently). Gating on row-
                // presence leaves the customer permanently charged.
                $shouldDispatchRefund = true;

                return;
            }

            $locked->forceFill([
                'payment_status' => PaymentStatus::Paid,
                'stripe_payment_intent_id' => $piId,
                'stripe_charge_id' => $chargeIdValue,
                'paid_amount_cents' => $amount,
                'currency' => $currencyLower,
                'paid_at' => now(),
            ])->save();

            $shouldDispatchRefund = true;
        });

        if (! $shouldDispatchRefund) {
            // Either the booking was already refunded/refund_failed or not
            // in the right shape; guards inside the transaction logged as
            // needed.
            return new Response('OK', 200);
        }

        $booking = $booking->fresh() ?? $booking;

        try {
            $result = $this->refundService->refund($booking, null, 'cancelled-after-payment');
        } catch (ApiConnectionException|RateLimitException|ApiErrorException $e) {
            // Transient Stripe error — `RefundService` left the row at
            // `pending`, ready for retry with the same UUID. Return 5xx
            // so Stripe re-delivers this webhook; the next delivery will
            // land on the `Paid + pending refund row` branch above and
            // re-dispatch with the same idempotency key.
            Log::warning('Connect late-webhook refund: transient Stripe error on refunds.create — returning 503 to force Stripe retry', [
                'event_id' => $eventId,
                'booking_id' => $booking->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new Response('Transient Stripe error — retry.', 503, ['Retry-After' => '60']);
        }

        if ($result->bookingRefund !== null) {
            $this->upsertCancelledAfterPaymentPendingAction($booking, $result);
            $this->dispatchCancelledAfterPaymentEmail($booking, $result);
        }

        return new Response('OK', 200);
    }

    /**
     * One `payment.cancelled_after_payment` Pending Action per booking.
     * `updateOrCreate` on `(business_id, type, payload->>booking_id)` is
     * the idempotency key; replays of the same event id (or fresh ids on
     * an already-refunded booking) converge on the existing row.
     */
    private function upsertCancelledAfterPaymentPendingAction(
        Booking $booking,
        RefundResult $result,
    ): void {
        $row = $result->bookingRefund;
        if ($row === null) {
            return;
        }

        $payload = [
            'booking_id' => $booking->id,
            'booking_refund_id' => $row->id,
            'customer_name' => $booking->customer?->name,
            'customer_email' => $booking->customer?->email,
            'customer_phone' => $booking->customer?->phone,
            'amount_cents' => $row->amount_cents,
            'currency' => $row->currency,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'refund_outcome' => $result->outcome,
            'stripe_refund_id' => $row->stripe_refund_id,
        ];

        $existing = PendingAction::where('business_id', $booking->business_id)
            ->where('type', PendingActionType::PaymentCancelledAfterPayment->value)
            ->where('payload->booking_id', $booking->id)
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['payload' => $payload])->save();

            return;
        }

        PendingAction::create([
            'business_id' => $booking->business_id,
            'booking_id' => $booking->id,
            'type' => PendingActionType::PaymentCancelledAfterPayment,
            'payload' => $payload,
            'status' => PendingActionStatus::Pending,
        ]);
    }

    private function dispatchCancelledAfterPaymentEmail(
        Booking $booking,
        RefundResult $result,
    ): void {
        $row = $result->bookingRefund;
        if ($row === null) {
            return;
        }

        $booking->loadMissing(['business.admins']);
        $admins = $booking->business->admins;

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new CancelledAfterPaymentNotification($booking, $row));
    }

    /**
     * PAYMENTS Session 3 — refund-settlement webhook handler (locked
     * decisions #33 + D-167 + D-171 + D-172).
     *
     * Three event shapes converge here:
     *  - `charge.refunded`       — data.object = Charge, whose
     *                              `refunds->data[]` carries the Refund(s);
     *  - `charge.refund.updated` — data.object = Refund;
     *  - `refund.updated`        — data.object = Refund.
     *
     * Both `.refund.updated` variants exist across Stripe API versions —
     * subscribing to both belts-and-braces against dashboard test-endpoint
     * drift. The D-092 event-id cache dedup collapses exact-duplicate event
     * ids; different ids for the same state transition converge via the
     * outcome-level guard inside `RefundService::recordStripeState`.
     *
     * Row match (D-171): `booking_refunds.stripe_refund_id`. If no row
     * matches, log warning + 200 (Stripe won't retry on 2xx; an untracked
     * refund id is either a test-environment leftover or a legitimately
     * orphaned refund). Cross-account guard via the D-158 pin on the
     * booking: the refund must belong to the SAME `stripe_account` that
     * minted the original Checkout session.
     */
    private function handleRefundEvent(StripeEvent $event): Response
    {
        $refunds = $this->extractRefundsFromEvent($event);
        if ($refunds === []) {
            Log::warning('Connect refund event: no Refund objects extracted from payload', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return new Response('OK', 200);
        }

        $actualAccount = $event->account ?? null;

        foreach ($refunds as $stripeRefund) {
            $refundId = is_string($stripeRefund->id ?? null) ? $stripeRefund->id : null;
            if ($refundId === null) {
                continue;
            }

            $row = BookingRefund::where('stripe_refund_id', $refundId)->first();
            if ($row === null) {
                // Codex Round 2 P2: `RefundService::refund` bubbles
                // transient Stripe errors (5xx / 429 / connection drop) so
                // the caller can return 5xx and let Stripe retry. If
                // Stripe had actually CREATED the refund before the
                // transport failed (response-loss), our row is left
                // `Pending` with `stripe_refund_id = null`. A later
                // `charge.refunded` / `refund.updated` would then miss the
                // row and silently drop — leaving the customer refunded
                // but the booking still `Paid`. Fall back to matching via
                // `(payment_intent, amount_cents, status=pending)` and
                // backfill the `stripe_refund_id` so future events
                // converge.
                $row = $this->resolveRefundRowByFallback($stripeRefund, $refundId, $event);

                if ($row === null) {
                    Log::warning('Connect refund event: no booking_refunds row matches stripe_refund_id (and no payment_intent/amount fallback)', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'stripe_refund_id' => $refundId,
                    ]);

                    continue;
                }
            }

            // D-158 cross-account guard via the booking's pin.
            $row->loadMissing('booking');
            $expectedAccount = $row->booking?->stripe_connected_account_id;
            if (! is_string($expectedAccount) || $expectedAccount === '' || $actualAccount !== $expectedAccount) {
                Log::critical('Connect refund event cross-account mismatch', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                    'booking_refund_id' => $row->id,
                    'expected_account' => $expectedAccount,
                    'actual_account' => $actualAccount,
                ]);

                continue;
            }

            $stripeStatus = is_string($stripeRefund->status ?? null) ? $stripeRefund->status : '';
            $failureReason = is_string($stripeRefund->failure_reason ?? null) ? $stripeRefund->failure_reason : null;

            $this->refundService->recordStripeState($row, $stripeStatus, $failureReason);
        }

        return new Response('OK', 200);
    }

    /**
     * Codex Round 2 P2 fallback: resolve a `booking_refunds` row when the
     * primary `stripe_refund_id` lookup missed (response-loss scenario).
     *
     * The webhook's Refund object carries `payment_intent` + `amount`. Look
     * up the booking via `stripe_payment_intent_id` (the D-152 pin),
     * then find the most recent Pending row on that booking whose
     * `amount_cents` matches the Stripe refund amount AND whose
     * `stripe_refund_id` is still null. Backfill the id so subsequent
     * events converge via the primary path.
     *
     * Returns null when the fallback also misses — the caller logs +
     * continues to the next refund.
     */
    private function resolveRefundRowByFallback(StripeRefund $stripeRefund, string $refundId, StripeEvent $event): ?BookingRefund
    {
        $paymentIntentId = is_string($stripeRefund->payment_intent ?? null) ? $stripeRefund->payment_intent : null;
        $amount = is_int($stripeRefund->amount ?? null) ? $stripeRefund->amount : null;

        if ($paymentIntentId === null || $amount === null || $amount <= 0) {
            return null;
        }

        $booking = Booking::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($booking === null) {
            return null;
        }

        $row = $booking->bookingRefunds()
            ->whereNull('stripe_refund_id')
            ->where('amount_cents', $amount)
            ->where('status', BookingRefundStatus::Pending->value)
            ->latest('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $row->forceFill(['stripe_refund_id' => $refundId])->save();

        Log::info('Connect refund event: resolved row via payment_intent+amount fallback; backfilled stripe_refund_id', [
            'event_id' => $event->id,
            'booking_refund_id' => $row->id,
            'stripe_refund_id' => $refundId,
        ]);

        return $row;
    }

    /**
     * @return array<int, StripeRefund>
     */
    private function extractRefundsFromEvent(StripeEvent $event): array
    {
        $object = $event->data->object ?? null;
        if ($object instanceof StripeRefund) {
            return [$object];
        }

        if ($object instanceof StripeCharge) {
            // `$object->refunds->data` is typed as `Refund[]` by the Stripe
            // SDK; return it directly.
            return $object->refunds->data ?? [];
        }

        return [];
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
