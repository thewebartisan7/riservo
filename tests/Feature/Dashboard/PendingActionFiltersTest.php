<?php

use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Business;
use App\Models\PendingAction;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

/**
 * D-113 / locked roadmap decision #44 — pending_actions table is generalised
 * for payment Pending Actions. Calendar-only readers MUST scope to calendar
 * types so payment-typed rows do not leak into calendar surfaces.
 */
test('payment-typed pending actions do not inflate calendarPendingActionsCount Inertia prop', function () {
    PendingAction::create([
        'business_id' => $this->business->id,
        'integration_id' => null,
        'type' => PendingActionType::PaymentDisputeOpened,
        'payload' => ['note' => 'fixture'],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('calendarPendingActionsCount', 0));
});

test('payment-typed pending actions do not appear in dashboard pending list', function () {
    PendingAction::create([
        'business_id' => $this->business->id,
        'integration_id' => null,
        'type' => PendingActionType::PaymentRefundFailed,
        'payload' => ['reason' => 'fixture'],
        'status' => PendingActionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('calendarPendingActions', []));
});

test('PendingActionType::calendarValues excludes payment cases', function () {
    $values = PendingActionType::calendarValues();

    expect($values)
        ->toContain(PendingActionType::RiservoEventDeletedInGoogle->value)
        ->toContain(PendingActionType::ExternalBookingConflict->value)
        ->not->toContain(PendingActionType::PaymentDisputeOpened->value)
        ->not->toContain(PendingActionType::PaymentRefundFailed->value)
        ->not->toContain(PendingActionType::PaymentCancelledAfterPayment->value);
});
