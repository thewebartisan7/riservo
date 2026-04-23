<?php

/**
 * Structural regression guard (mirrors the D-148 R12-2 pattern for
 * ConnectedAccountController). The behavioural tests in
 * CheckoutSessionCompletedWebhookTest + CheckoutSuccessReturnTest cover
 * happy paths and idempotency, but Pest cannot reliably simulate a true
 * multi-connection race without a custom harness.
 *
 * This test inspects `app/Services/Payments/CheckoutPromoter.php` directly
 * and asserts the source contains all three invariants the shared
 * promotion service must hold:
 *
 *   1. `DB::transaction(` — the state transition is wrapped in a transaction.
 *   2. `lockForUpdate(` — the booking row is locked inside the transaction
 *      so concurrent promoters (webhook vs success-page) serialise cleanly.
 *   3. An outcome-level `PaymentStatus::Paid` guard — replays and
 *      inline-promotion-then-webhook races no-op after the first caller
 *      through the critical section (locked roadmap decision #33).
 *
 * A regression that removes any of the three fails here loudly instead
 * of surfacing as a production race under load.
 */
test('CheckoutPromoter source carries the lockForUpdate + DB::transaction + outcome-level guard', function () {
    // Use a relative path from the test file rather than `app_path()` so
    // this file stays a pure unit test (no Laravel bootstrap required).
    // __DIR__ from tests/Unit/Services/Payments → 4 levels up lands at repo root.
    $source = file_get_contents(dirname(__DIR__, 4).'/app/Services/Payments/CheckoutPromoter.php');

    expect(str_contains($source, 'DB::transaction('))->toBeTrue();
    expect(str_contains($source, 'lockForUpdate('))->toBeTrue();
    expect(str_contains($source, 'PaymentStatus::Paid'))->toBeTrue();
});
