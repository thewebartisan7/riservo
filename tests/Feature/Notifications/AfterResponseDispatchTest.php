<?php

/*
 * These tests assert that the four interactive notifications
 * (MagicLink + the three InvitationNotification call sites) dispatch
 * AFTER the HTTP response is flushed — per D-075.
 *
 * We use Bus::fake() rather than Notification::fake() here because the
 * `dispatch(fn () => ...)->afterResponse()` closure hits the Bus at the
 * PendingClosureDispatch layer (as a CallQueuedClosure job with
 * afterResponse=true). The Bus fake captures that with
 * assertDispatchedAfterResponse(). Notification::fake() would only see
 * the inner notify() call after the closure ran on terminating — it
 * cannot distinguish sync-dispatched from after-response-dispatched.
 * The existing MagicLinkTest / StaffTest / Step4InvitationsTest suites
 * already cover the end-to-end "notification arrives at the right
 * notifiable" assertion via Notification::fake(); this file covers the
 * orthogonal "dispatch was deferred past response flush" property.
 */

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;

test('magic link notification dispatches after response', function () {
    Bus::fake();

    User::factory()->create(['email' => 'user@example.com']);

    $this->post('/magic-link', ['email' => 'user@example.com'])
        ->assertSessionHas('status');

    Bus::assertDispatchedAfterResponse(CallQueuedClosure::class, 1);
});

test('invitation notification (staff page) dispatches after response', function () {
    Bus::fake();

    $business = Business::factory()->onboarded()->create();
    $admin = User::factory()->create();
    attachAdmin($business, $admin);

    $this->actingAs($admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => 'new@example.com',
        ])
        ->assertRedirect('/dashboard/settings/staff');

    expect(BusinessInvitation::where('email', 'new@example.com')->exists())->toBeTrue();
    Bus::assertDispatchedAfterResponse(CallQueuedClosure::class, 1);
});

test('invitation notification (resend) dispatches after response', function () {
    Bus::fake();

    $business = Business::factory()->onboarded()->create();
    $admin = User::factory()->create();
    attachAdmin($business, $admin);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'expires_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->post("/dashboard/settings/staff/invitations/{$invitation->id}/resend")
        ->assertRedirect();

    Bus::assertDispatchedAfterResponse(CallQueuedClosure::class, 1);
});

test('invitation notification (onboarding) dispatches after response', function () {
    Bus::fake();

    $business = Business::factory()->create(['onboarding_step' => 4]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($business, $admin);
    $service = Service::factory()->create(['business_id' => $business->id]);

    $this->actingAs($admin)
        ->postJson('/onboarding/step/4', [
            'invitations' => [
                ['email' => 'alice@example.com', 'service_ids' => [$service->id]],
                ['email' => 'bob@example.com', 'service_ids' => []],
            ],
        ])
        ->assertRedirect('/onboarding/step/5');

    Bus::assertDispatchedAfterResponse(CallQueuedClosure::class, 2);
});
