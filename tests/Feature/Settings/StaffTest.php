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
            ->has('staff', 1)
        );
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

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/staff/invite', [
            'email' => 'new@example.com',
        ])
        ->assertRedirect('/dashboard/settings/staff');

    expect(BusinessInvitation::where('email', 'new@example.com')->exists())->toBeTrue();
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
    ]);

    $oldToken = $invitation->token;

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/staff/invitations/{$invitation->id}/resend")
        ->assertRedirect();

    expect($invitation->fresh()->token)->not->toBe($oldToken);
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
