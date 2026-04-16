<?php

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);
});

test('admin can view staff list', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/staff')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/staff/index')
            ->has('staff', 2)
        );
});

test('staff index exposes invite expiry hours from BusinessInvitation::EXPIRY_HOURS', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/staff')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inviteExpiryHours', BusinessInvitation::EXPIRY_HOURS)
        );
});

test('staff list includes the admin with role and provider flags', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/staff')
        ->assertOk()
        ->assertInertia(function ($page) {
            $staff = $page->toArray()['props']['staff'];
            $admin = collect($staff)->firstWhere('id', $this->admin->id);
            $staffMember = collect($staff)->firstWhere('id', $this->staff->id);

            expect($admin)->not->toBeNull();
            expect($admin['role'])->toBe('admin');
            expect($admin['is_provider'])->toBeFalse();
            expect($admin['is_self'])->toBeTrue();

            expect($staffMember['role'])->toBe('staff');
            expect($staffMember['is_provider'])->toBeTrue();
            expect($staffMember['is_self'])->toBeFalse();

            return $page;
        });
});

test('staff list marks admin as provider when they have an active provider row', function () {
    attachProvider($this->business, $this->admin);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/staff')
        ->assertOk()
        ->assertInertia(function ($page) {
            $staff = $page->toArray()['props']['staff'];
            $admin = collect($staff)->firstWhere('id', $this->admin->id);

            expect($admin['is_provider'])->toBeTrue();
            expect($admin['role'])->toBe('admin');

            return $page;
        });
});

test('admin can view staff detail', function () {
    $this->actingAs($this->admin)
        ->get("/dashboard/settings/staff/{$this->staff->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/staff/show')
            ->has('member')
            ->has('schedule', 7)
            ->has('exceptions')
        );
});

test('admin can invite staff', function () {
    Notification::fake();

    $this->travelTo(now()->startOfMinute());

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => 'new@example.com',
        ])
        ->assertRedirect('/dashboard/settings/staff');

    $invitation = BusinessInvitation::where('email', 'new@example.com')->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->expires_at->equalTo(BusinessInvitation::defaultExpiresAt()))->toBeTrue();
});

test('cannot invite existing member', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => $this->staff->email,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('admin can resend invitation', function () {
    Notification::fake();

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $this->business->id,
        'expires_at' => now()->subHour(),
    ]);

    $oldToken = $invitation->token;

    $this->travelTo(now()->startOfMinute());

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/staff/invitations/{$invitation->id}/resend")
        ->assertRedirect();

    $fresh = $invitation->fresh();
    expect($fresh->token)->not->toBe($oldToken);
    expect($fresh->expires_at->equalTo(BusinessInvitation::defaultExpiresAt()))->toBeTrue();
});

test('admin can cancel invitation', function () {
    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $this->business->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/staff/invitations/{$invitation->id}")
        ->assertRedirect();

    expect(BusinessInvitation::find($invitation->id))->toBeNull();
});

test('admin can upload staff avatar', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/staff/{$this->staff->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    expect($this->staff->fresh()->avatar)->not->toBeNull();
    Storage::disk('public')->assertExists($this->staff->fresh()->avatar);
});

test('cannot manage staff from another business', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherStaff = User::factory()->create();
    attachProvider($otherBusiness, $otherStaff);

    $this->actingAs($this->admin)
        ->get("/dashboard/settings/staff/{$otherStaff->id}")
        ->assertForbidden();
});
