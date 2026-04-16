<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\BusinessMember;
use App\Models\Provider;
use App\Models\User;

test('soft-deleted business_members row does not block a new active row', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();

    $business->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
    $first = BusinessMember::query()
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
    $first->delete();

    $business->members()->attach($user, ['role' => BusinessMemberRole::Admin->value]);

    $rows = BusinessMember::withTrashed()
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->whereNotNull('deleted_at'))->toHaveCount(1);
    expect($rows->whereNull('deleted_at'))->toHaveCount(1);
});

test('Business::members() excludes soft-deleted rows', function () {
    $business = Business::factory()->create();
    $active = User::factory()->create();
    $trashed = User::factory()->create();

    $business->members()->attach($active, ['role' => BusinessMemberRole::Staff->value]);
    $business->members()->attach($trashed, ['role' => BusinessMemberRole::Staff->value]);
    BusinessMember::query()
        ->where('business_id', $business->id)
        ->where('user_id', $trashed->id)
        ->firstOrFail()
        ->delete();

    $ids = $business->members()->pluck('users.id')->all();

    expect($ids)->toBe([$active->id]);
});

test('User::businesses() excludes soft-deleted rows', function () {
    $user = User::factory()->create();
    $active = Business::factory()->create();
    $trashed = Business::factory()->create();

    $active->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
    $trashed->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
    BusinessMember::query()
        ->where('business_id', $trashed->id)
        ->where('user_id', $user->id)
        ->firstOrFail()
        ->delete();

    $ids = $user->businesses()->pluck('businesses.id')->all();

    expect($ids)->toBe([$active->id]);
});

test('attachOrRestoreMember restores a soft-deleted row instead of duplicating', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();

    $business->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
    $original = BusinessMember::query()
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
    $originalId = $original->id;
    $original->delete();

    $restored = $business->attachOrRestoreMember($user, BusinessMemberRole::Admin);

    expect($restored->id)->toBe($originalId);
    expect($restored->trashed())->toBeFalse();
    expect($restored->role)->toBe(BusinessMemberRole::Admin);

    $count = BusinessMember::withTrashed()
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->count();
    expect($count)->toBe(1);
});

test('full cycle: invite then soft-delete membership then re-invite lands on restored row', function () {
    $business = Business::factory()->create();
    $invitee = User::factory()->create(['email' => 'returning@example.com']);

    $business->members()->attach($invitee, ['role' => BusinessMemberRole::Staff->value]);
    $membership = BusinessMember::query()
        ->where('business_id', $business->id)
        ->where('user_id', $invitee->id)
        ->firstOrFail();
    $originalMembershipId = $membership->id;
    $membership->delete();

    expect($business->members()->where('users.id', $invitee->id)->exists())->toBeFalse();

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'returning@example.com',
    ]);

    $this->post('/invite/'.$invitation->token, [
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $allRows = BusinessMember::withTrashed()
        ->where('business_id', $business->id)
        ->where('user_id', $invitee->id)
        ->get();

    expect($allRows)->toHaveCount(1);
    expect($allRows->first()->id)->toBe($originalMembershipId);
    expect($allRows->first()->trashed())->toBeFalse();

    expect(Provider::where('business_id', $business->id)->where('user_id', $invitee->id)->exists())->toBeTrue();
    expect($invitation->fresh()->isAccepted())->toBeTrue();
});
