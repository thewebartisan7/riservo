<?php

/**
 * PAYMENTS Session 3 — `charge.dispute.*` webhook trio (locked decision #35).
 *
 * Behaviour under test:
 *  - `created` writes the Pending Action, links to the booking via
 *    `stripe_payment_intent_id`, dispatches `DisputeOpenedNotification` to
 *    admins only (locked decision #35 + #19 — staff do not receive).
 *  - `updated` refreshes the PA payload without re-sending the email.
 *  - `closed` resolves the PA with `resolution_note = 'closed:<status>'` and
 *    dispatches `DisputeClosedNotification`.
 *  - Booking `status` + `payment_status` are NEVER mutated across the trio
 *    (locked decision #25).
 */

use App\Enums\BookingStatus;
use App\Enums\BusinessMemberRole;
use App\Enums\PaymentStatus;
use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Booking;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use App\Notifications\Payments\DisputeClosedNotification;
use App\Notifications\Payments\DisputeOpenedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Billing\StripeEventBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['services.stripe.connect_webhook_secret' => null]);
    Cache::flush();

    $this->business = Business::factory()->onboarded()->create(['country' => 'CH']);
    StripeConnectedAccount::factory()->active()->for($this->business)->create([
        'stripe_account_id' => 'acct_test_dispute',
    ]);
    $this->admin = User::factory()->create();
    $this->business->attachOrRestoreMember($this->admin, BusinessMemberRole::Admin);

    $this->staff = User::factory()->create();
    $this->business->attachOrRestoreMember($this->staff, BusinessMemberRole::Staff);
});

function disputeBooking(array $overrides = []): Booking
{
    return Booking::factory()->paid()->create(array_merge([
        'business_id' => test()->business->id,
        'stripe_connected_account_id' => 'acct_test_dispute',
        'stripe_payment_intent_id' => 'pi_test_dispute_x',
        'paid_amount_cents' => 5000,
        'currency' => 'chf',
    ], $overrides));
}

test('charge.dispute.created writes PA + dispatches admin email + does not change booking state', function () {
    $booking = disputeBooking();

    $payload = StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.created',
        disputeId: 'dp_test_abc',
        paymentIntentId: 'pi_test_dispute_x',
    );

    $response = $this->postJson('/webhooks/stripe-connect', $payload);
    $response->assertOk();

    $pa = PendingAction::where('type', PendingActionType::PaymentDisputeOpened->value)->first();
    expect($pa)->not->toBeNull();
    expect($pa->status)->toBe(PendingActionStatus::Pending);
    expect($pa->booking_id)->toBe($booking->id);
    expect($pa->payload['dispute_id'])->toBe('dp_test_abc');

    Notification::assertSentTo($this->admin, DisputeOpenedNotification::class);
    Notification::assertNotSentTo($this->staff, DisputeOpenedNotification::class);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->payment_status)->toBe(PaymentStatus::Paid);
});

test('charge.dispute.updated refreshes payload without re-sending email', function () {
    disputeBooking();

    // created first
    $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.created',
        disputeId: 'dp_test_upd',
        paymentIntentId: 'pi_test_dispute_x',
    ))->assertOk();

    Notification::assertSentToTimes($this->admin, DisputeOpenedNotification::class, 1);

    // updated: fresh event id, same dispute id
    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.updated',
        disputeId: 'dp_test_upd',
        paymentIntentId: 'pi_test_dispute_x',
        overrides: ['status' => 'warning_needs_response', 'amount' => 5500],
    ));
    $response->assertOk();

    // Still exactly 1 opened-email dispatched; no closed emails yet.
    Notification::assertSentToTimes($this->admin, DisputeOpenedNotification::class, 1);
    Notification::assertNotSentTo($this->admin, DisputeClosedNotification::class);

    $pa = PendingAction::where('payload->dispute_id', 'dp_test_upd')->first();
    expect($pa->status)->toBe(PendingActionStatus::Pending);
    expect($pa->payload['amount'])->toBe(5500);
});

test('charge.dispute.closed resolves PA + dispatches closed email with outcome', function () {
    disputeBooking();

    $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.created',
        disputeId: 'dp_test_cls',
        paymentIntentId: 'pi_test_dispute_x',
    ))->assertOk();

    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.closed',
        disputeId: 'dp_test_cls',
        paymentIntentId: 'pi_test_dispute_x',
        overrides: ['status' => 'won'],
    ));
    $response->assertOk();

    $pa = PendingAction::where('payload->dispute_id', 'dp_test_cls')->first();
    expect($pa->status)->toBe(PendingActionStatus::Resolved);
    expect($pa->resolution_note)->toBe('closed:won');

    Notification::assertSentTo($this->admin, DisputeClosedNotification::class);
});

test('admin dismiss on dispute PA writes resolution_note=dismissed-by-admin', function () {
    $pa = PendingAction::create([
        'business_id' => $this->business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_manual'],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/payment-pending-actions/{$pa->id}/resolve");

    $response->assertRedirect();

    $pa->refresh();
    expect($pa->status)->toBe(PendingActionStatus::Resolved);
    expect($pa->resolution_note)->toBe('dismissed-by-admin');
});

test('admin of Business A cannot dismiss a dispute PA of Business B (404)', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherAdmin = User::factory()->create();
    $otherBusiness->attachOrRestoreMember($otherAdmin, BusinessMemberRole::Admin);

    $pa = PendingAction::create([
        'business_id' => $this->business->id,  // belongs to OUR business
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_cross_tenant'],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($otherAdmin)
        ->withSession(['current_business_id' => $otherBusiness->id])
        ->patch("/dashboard/payment-pending-actions/{$pa->id}/resolve");

    $response->assertNotFound();

    expect($pa->fresh()->status)->toBe(PendingActionStatus::Pending);
});

test('staff cannot dismiss a dispute PA (403)', function () {
    $pa = PendingAction::create([
        'business_id' => $this->business->id,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['dispute_id' => 'dp_staff_fail'],
        'status' => PendingActionStatus::Pending,
    ]);

    $response = $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch("/dashboard/payment-pending-actions/{$pa->id}/resolve");

    $response->assertForbidden();

    expect($pa->fresh()->status)->toBe(PendingActionStatus::Pending);
});

test('dispute-closed email interpolates Stripe status literally when outcome is neither won nor lost', function () {
    disputeBooking();

    $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.created',
        disputeId: 'dp_test_warn',
        paymentIntentId: 'pi_test_dispute_x',
    ))->assertOk();

    // Render a `warning_closed` outcome (neither won nor lost) — this hits
    // the blade's fallback branch that previously contained a literal `%s`.
    Notification::fake();  // reset captured notifications for a clean assert
    $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_dispute',
        'charge.dispute.closed',
        disputeId: 'dp_test_warn',
        paymentIntentId: 'pi_test_dispute_x',
        overrides: ['status' => 'warning_closed'],
    ))->assertOk();

    Notification::assertSentTo($this->admin, DisputeClosedNotification::class, function ($notification, $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);
        $rendered = (string) $mail->render();

        // The fixed template interpolates `$stripeStatus`; rendered output
        // must NOT contain a raw `%s` placeholder.
        expect(str_contains($rendered, '%s'))->toBeFalse();
        expect(str_contains($rendered, 'warning_closed'))->toBeTrue();

        return true;
    });
});

test('dispute event for unknown stripe_account_id logs critical + 200s', function () {
    $response = $this->postJson('/webhooks/stripe-connect', StripeEventBuilder::disputeEvent(
        'acct_test_unknown_xxxxx',
        'charge.dispute.created',
    ));

    $response->assertOk();
    expect(PendingAction::count())->toBe(0);
    Notification::assertNothingSent();
});
