<?php

use App\Enums\PaymentMode;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    // Empty-secret escape hatch mirrors the MVPC-3 WebhookTest pattern — with
    // no configured secret the controller falls back to parsing the raw JSON
    // payload, which is what we POST below. A dedicated signature-mismatch
    // case configures a real secret and asserts the 400 response.
    config(['services.stripe.connect_webhook_secret' => null]);
    Cache::flush();
});

function connectEvent(string $type, string $accountId, array $object = [], ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_test_'.uniqid(),
        'object' => 'event',
        'type' => $type,
        'account' => $accountId,
        'data' => [
            'object' => array_merge(['id' => $accountId, 'object' => 'account'], $object),
        ],
    ];
}

test('account.updated re-fetches via accounts.retrieve and persists fresh state', function () {
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Offline]);
    $row = StripeConnectedAccount::factory()->pending()->for($business)->create([
        'stripe_account_id' => 'acct_test_abc',
    ]);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_abc', [
        'country' => 'CH',
        'default_currency' => 'chf',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    $response = $this->postJson('/webhooks/stripe-connect', connectEvent('account.updated', 'acct_test_abc', [
        // Payload-reported fields are IGNORED per locked decision #34.
        'charges_enabled' => false,
    ]));

    $response->assertOk();

    $row->refresh();
    expect($row->charges_enabled)->toBeTrue()
        ->and($row->payouts_enabled)->toBeTrue()
        ->and($row->details_submitted)->toBeTrue()
        ->and($row->default_currency)->toBe('chf');
});

test('account.updated with state matching local row is a no-op (outcome-level idempotency)', function () {
    $business = Business::factory()->create();
    $row = StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_noop',
    ]);
    $updatedAtBefore = $row->updated_at;

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_noop', [
        'country' => 'CH',
        'default_currency' => 'chf',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    $this->travel(5)->seconds();
    $this->postJson('/webhooks/stripe-connect', connectEvent('account.updated', 'acct_test_noop'))->assertOk();

    $row->refresh();
    expect($row->updated_at->timestamp)->toBe($updatedAtBefore->timestamp);
});

test('account.updated transitioning charges_enabled true->false demotes payment_mode to offline', function () {
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_demote',
    ]);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_demote', [
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => true,
        'requirements' => (object) [
            'currently_due' => ['external_account'],
            'disabled_reason' => null,
        ],
    ]);

    $this->postJson('/webhooks/stripe-connect', connectEvent('account.updated', 'acct_test_demote'))->assertOk();

    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('account.updated converges to Stripe-authoritative state even when payload disagrees', function () {
    // Locked roadmap decision #34: the payload is a nudge only. A stale
    // older event with `charges_enabled = true` arriving after a newer
    // capability loss must NOT leave the row as 'true' — the handler
    // re-fetches authoritative state and writes that.
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_stale',
    ]);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_stale', [
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => true,
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    // Payload carries a stale "all-good" snapshot; retrieve contradicts.
    $this->postJson('/webhooks/stripe-connect', connectEvent('account.updated', 'acct_test_stale', [
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
    ]))->assertOk();

    $row = StripeConnectedAccount::where('stripe_account_id', 'acct_test_stale')->first();
    expect($row->charges_enabled)->toBeFalse();
    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('account.updated handler wraps retrieve+save in a transaction with lockForUpdate for per-account serialisation (D-136, codex Round 7)', function () {
    // Codex Round 7 finding: the prior implementation fetched Stripe state
    // BEFORE locking the row, so two concurrent deliveries could interleave
    // fetch+save and an older snapshot could overwrite a newer one. The fix
    // wraps the retrieve+save in a single `DB::transaction` that locks the
    // row up-front via `lockForUpdate`, serialising per-account processing
    // so that whoever acquires the lock second sees a Stripe state that is
    // never older than what the first committed.
    //
    // We prove the invariant via a reflection-shaped assertion — the
    // structural test (lockForUpdate is invoked on the row query during the
    // transaction) matches what prevents the race in production. Pest
    // cannot reliably simulate true concurrency in-process; an integration
    // test with two parallel workers would belong in an ops-level smoke
    // test, not the unit suite.
    $source = file_get_contents(app_path('Http/Controllers/Webhooks/StripeConnectWebhookController.php'));

    expect($source)
        ->toContain('lockForUpdate')
        ->and($source)
        ->toMatch('/DB::transaction\([^)]*function[^}]*lockForUpdate/s');
});

test('account.updated retry finishes demotion even when row already matches authoritative state (D-128, codex Round 4)', function () {
    // Codex Round 4 finding: the matches() short-circuit used to bail before
    // re-checking the business demotion. If a prior delivery saved the row
    // but failed to save the business, every subsequent Stripe retry would
    // 200-noop forever and leave the Business stuck at `online` despite
    // Stripe having revoked the capabilities. Now the demotion check runs
    // even when the row matches.
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);
    StripeConnectedAccount::factory()->incomplete()->for($business)->create([
        'stripe_account_id' => 'acct_test_retry_demote',
        // Capability snapshot already matches what Stripe will report below
        // (charges_enabled=false, payouts_enabled=false, details_submitted=true).
    ]);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_retry_demote', [
        'country' => 'CH',
        'default_currency' => 'chf',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => true,
        'requirements' => (object) [
            'currently_due' => ['external_account', 'tos_acceptance.date'],
            'disabled_reason' => null,
        ],
    ]);

    $this->postJson('/webhooks/stripe-connect', connectEvent('account.updated', 'acct_test_retry_demote'))
        ->assertOk();

    // Even though the row already matched, the business was demoted.
    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('account.application.deauthorized for a retired acct does NOT demote a business that has reconnected (D-139, codex Round 8)', function () {
    // Codex Round 8 finding: late-arriving deauthorize events for retired
    // `acct_…` used to demote a business that had already reconnected with
    // a fresh active account. The fix: after trashing the matched row,
    // only force payment_mode=offline when no OTHER active connected
    // account remains for this business. Historical soft-deleted rows
    // never mutate current business state.
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);

    // Simulate the disconnect-then-reconnect history: the old account is
    // already soft-deleted; the new active account is in place.
    $oldRow = StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_retired',
    ]);
    $oldRow->delete();

    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_new_active',
    ]);

    // Stripe belatedly delivers a deauthorize for the OLD retired account.
    $this->postJson('/webhooks/stripe-connect', connectEvent(
        'account.application.deauthorized',
        'acct_test_retired',
    ))->assertOk();

    // Business stays at Online (the reconnected active account is healthy).
    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Online);
    // The new active row is untouched.
    expect(StripeConnectedAccount::where('business_id', $business->id)->count())
        ->toBe(1)
        ->and(StripeConnectedAccount::where('business_id', $business->id)->first()->stripe_account_id)
        ->toBe('acct_test_new_active');
});

test('account.application.deauthorized retry on already-trashed row still finishes business demotion (D-128)', function () {
    // Codex Round 4: the lookup used to scope out trashed rows, so a retry
    // after the row was already soft-deleted would not find it and 200-noop,
    // leaving the Business stuck at `online`. The lookup now uses
    // withTrashed() so the retry finds the row and finishes demoting.
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);
    $row = StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_retry_deauth',
    ]);

    // Simulate "the previous delivery soft-deleted the row but the business
    // save crashed" by manually soft-deleting the row + leaving the business
    // at online.
    $row->delete();
    expect($row->fresh()->trashed())->toBeTrue();

    $this->postJson('/webhooks/stripe-connect', connectEvent(
        'account.application.deauthorized',
        'acct_test_retry_deauth',
    ))->assertOk();

    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
    // Row stays trashed; stripe_account_id retained.
    expect($row->fresh()->trashed())->toBeTrue()
        ->and($row->fresh()->stripe_account_id)->toBe('acct_test_retry_deauth');
});

test('account.application.deauthorized soft-deletes the row and forces payment_mode to offline', function () {
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Online]);
    $row = StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_deauth',
    ]);

    $this->postJson('/webhooks/stripe-connect', connectEvent(
        'account.application.deauthorized',
        'acct_test_deauth',
    ))->assertOk();

    // Row is soft-deleted; stripe_account_id retained for audit.
    $row->refresh();
    expect($row->trashed())->toBeTrue();
    expect($row->stripe_account_id)->toBe('acct_test_deauth');
    expect($business->fresh()->payment_mode)->toBe(PaymentMode::Offline);
});

test('charge.dispute.created persists a payment.dispute_opened Pending Action (D-123, codex Round 2)', function () {
    // Codex Round 2 finding: the prior log-and-200 stub combined with the
    // dedup cache silently swallowed real disputes that fired before
    // Session 3's email + UI pipeline ships. Now Session 1 persists an
    // actionable record so no dispute is lost.
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_dispute',
    ]);

    $payload = [
        'id' => 'evt_dispute_created_'.uniqid(),
        'object' => 'event',
        'type' => 'charge.dispute.created',
        'account' => 'acct_test_dispute',
        'data' => [
            'object' => [
                'id' => 'dp_test_abc',
                'object' => 'dispute',
                'charge' => 'ch_test_123',
                'amount' => 5000,
                'currency' => 'chf',
                'reason' => 'fraudulent',
                'status' => 'needs_response',
                'evidence_details' => ['due_by' => 1719000000],
            ],
        ],
    ];

    $this->postJson('/webhooks/stripe-connect', $payload)->assertOk();

    $action = PendingAction::where('business_id', $business->id)
        ->where('type', PendingActionType::PaymentDisputeOpened->value)
        ->first();

    expect($action)->not->toBeNull()
        ->and($action->status)->toBe(PendingActionStatus::Pending)
        ->and($action->payload['dispute_id'])->toBe('dp_test_abc')
        ->and($action->payload['charge_id'])->toBe('ch_test_123')
        ->and($action->payload['amount'])->toBe(5000)
        ->and($action->payload['reason'])->toBe('fraudulent');
});

test('charge.dispute.updated refreshes the existing Pending Action without changing status (D-123)', function () {
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_disp_upd',
    ]);

    $createdPayload = function (string $status) {
        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => 'charge.dispute.updated',
            'account' => 'acct_test_disp_upd',
            'data' => ['object' => [
                'id' => 'dp_test_upd',
                'object' => 'dispute',
                'charge' => 'ch_test_upd',
                'amount' => 1000,
                'currency' => 'chf',
                'reason' => 'fraudulent',
                'status' => $status,
            ]],
        ];
    };

    $created = $createdPayload('warning_needs_response');
    $created['type'] = 'charge.dispute.created';
    $this->postJson('/webhooks/stripe-connect', $created)->assertOk();

    $this->postJson('/webhooks/stripe-connect', $createdPayload('needs_response'))->assertOk();

    $rows = PendingAction::where('business_id', $business->id)
        ->where('type', PendingActionType::PaymentDisputeOpened->value)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->status)->toBe(PendingActionStatus::Pending);
    expect($rows->first()->payload['status'])->toBe('needs_response');
});

test('charge.dispute.closed resolves the Pending Action with the dispute outcome (D-123)', function () {
    $business = Business::factory()->create();
    StripeConnectedAccount::factory()->active()->for($business)->create([
        'stripe_account_id' => 'acct_test_disp_close',
    ]);

    $action = PendingAction::create([
        'business_id' => $business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_close'],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->postJson('/webhooks/stripe-connect', [
        'id' => 'evt_'.uniqid(),
        'object' => 'event',
        'type' => 'charge.dispute.closed',
        'account' => 'acct_test_disp_close',
        'data' => ['object' => [
            'id' => 'dp_test_close',
            'object' => 'dispute',
            'status' => 'won',
        ]],
    ])->assertOk();

    $action->refresh();
    expect($action->status)->toBe(PendingActionStatus::Resolved)
        ->and($action->resolution_note)->toBe('closed:won')
        ->and($action->resolved_at)->not->toBeNull();
});

test('partial unique index rejects a duplicate dispute_id row (D-126, codex Round 3)', function () {
    // The DB-enforced invariant behind the race-safe handler. Without the
    // partial unique index, two concurrent created+updated events for the
    // same dispute could both observe "no existing row" and each insert.
    $business = Business::factory()->create();

    PendingAction::create([
        'business_id' => $business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_unique', 'status' => 'needs_response'],
        'status' => PendingActionStatus::Pending,
    ]);

    expect(fn () => PendingAction::create([
        'business_id' => $business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_test_unique', 'status' => 'updated'],
        'status' => PendingActionStatus::Pending,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('dispute index permits duplicate dispute_ids across different types (D-126)', function () {
    // The index is partial — `WHERE type = 'payment.dispute_opened'`. Other
    // payment types or future ones can carry the same dispute_id without
    // colliding (defensive).
    $business = Business::factory()->create();

    PendingAction::create([
        'business_id' => $business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_other_type'],
        'status' => PendingActionStatus::Pending,
    ]);

    // A different type with the same dispute_id payload key is allowed.
    PendingAction::create([
        'business_id' => $business->id,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['dispute_id' => 'dp_other_type'],
        'status' => PendingActionStatus::Pending,
    ]);

    expect(PendingAction::where('payload->dispute_id', 'dp_other_type')->count())->toBe(2);
});

test('dispute event for unknown stripe_account_id logs critical + 200 (no row inserted)', function () {
    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'unknown stripe_account_id')
            && $ctx['stripe_account_id'] === 'acct_test_unknown');

    $this->postJson('/webhooks/stripe-connect', [
        'id' => 'evt_'.uniqid(),
        'object' => 'event',
        'type' => 'charge.dispute.created',
        'account' => 'acct_test_unknown',
        'data' => ['object' => ['id' => 'dp_x', 'object' => 'dispute']],
    ])->assertOk();

    expect(PendingAction::where('type', PendingActionType::PaymentDisputeOpened->value)->count())
        ->toBe(0);
});

test('account.updated for unknown stripe_account_id returns 503 (retryable, not cached) (D-149, codex Round 12)', function () {
    // Codex Round 12 finding: returning 200 for "unknown account" causes
    // the D-092 cache-dedup layer to capture the event id and Stripe's
    // retries to stop. If the event raced `create()` (webhook arrived
    // before the local DB transaction committed), we'd be permanently
    // stuck on the stale `accounts.create` snapshot. Returning 503 keeps
    // Stripe retrying; the dedup trait only caches 2xx so retries re-enter
    // the handler. No FakeStripeClient mock registered — the guard MUST
    // fire before any `accounts.retrieve` call; a regression would
    // explode on the unstubbed call.
    $eventId = 'evt_r12_unknown_updated';
    $response = $this->postJson(
        '/webhooks/stripe-connect',
        connectEvent('account.updated', 'acct_test_unknown_retry', eventId: $eventId),
    );

    $response->assertStatus(503);
    expect($response->headers->get('Retry-After'))->toBe('60');

    // Cache was NOT populated — the dedup trait only captures 2xx. The
    // next Stripe retry will therefore re-enter the handler rather than
    // short-circuit through the cache.
    expect(Cache::has('stripe:connect:event:'.$eventId))->toBeFalse();
});

test('account.application.deauthorized for unknown stripe_account_id returns 503 (retryable, not cached) (D-149)', function () {
    // Same rationale: a deauthorize event can (rarely) race the create +
    // insert sequence. Retryable 503 beats a silent 200+cache.
    $response = $this->postJson(
        '/webhooks/stripe-connect',
        connectEvent('account.application.deauthorized', 'acct_test_unknown_deauth'),
    );

    $response->assertStatus(503);
    expect($response->headers->get('Retry-After'))->toBe('60');
});

test('unknown event types return 200 without side-effects', function () {
    $response = $this->postJson('/webhooks/stripe-connect', connectEvent('some.unhandled.event', 'acct_test_xyz'));

    $response->assertOk();
});

test('invalid signature returns 400 when a webhook secret is configured', function () {
    config(['services.stripe.connect_webhook_secret' => 'whsec_connect_test']);

    $payload = connectEvent('account.updated', 'acct_test_invsig');
    $this->postJson('/webhooks/stripe-connect', $payload, ['Stripe-Signature' => 'not-a-valid-sig'])
        ->assertStatus(400);
});

test('empty connect_webhook_secret in non-testing environments fails closed (D-120, codex Round 1)', function () {
    // The empty-secret path is a testing-only escape hatch (the surrounding
    // tests use it). Outside `testing`, the controller MUST refuse to process
    // — otherwise an attacker who knows a real acct_… id could POST
    // account.application.deauthorized and force payment_mode = offline on
    // the target Business.
    config(['services.stripe.connect_webhook_secret' => '']);
    app()->detectEnvironment(fn () => 'production');

    try {
        $business = Business::factory()->create([
            'payment_mode' => PaymentMode::Online,
        ]);
        StripeConnectedAccount::factory()->active()->for($business)->create([
            'stripe_account_id' => 'acct_test_failclosed',
        ]);

        $this->postJson(
            '/webhooks/stripe-connect',
            connectEvent('account.application.deauthorized', 'acct_test_failclosed'),
        )->assertStatus(400);

        // The Business must NOT have been demoted — the request was rejected
        // before any handler ran.
        expect($business->fresh()->payment_mode)->toBe(PaymentMode::Online);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('cache prefix isolation — subscription and connect dedupe independently', function () {
    $business = Business::factory()->create(['payment_mode' => PaymentMode::Offline]);
    StripeConnectedAccount::factory()->pending()->for($business)->create([
        'stripe_account_id' => 'acct_test_prefix',
    ]);

    // Seed the subscription cache key for a given event id — proves it does
    // NOT short-circuit the Connect endpoint's dedup check.
    Cache::put('stripe:subscription:event:evt_shared_id', true, 60);

    FakeStripeClient::for($this)->mockAccountRetrieve('acct_test_prefix', [
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'default_currency' => 'chf',
        'requirements' => (object) ['currently_due' => [], 'disabled_reason' => null],
    ]);

    $payload = connectEvent('account.updated', 'acct_test_prefix', [], 'evt_shared_id');
    $this->postJson('/webhooks/stripe-connect', $payload)->assertOk();

    // Connect dedup namespace should now be populated with the same event id.
    expect(Cache::has('stripe:connect:event:evt_shared_id'))->toBeTrue();
    // Subscription dedup remains unchanged.
    expect(Cache::has('stripe:subscription:event:evt_shared_id'))->toBeTrue();
});
