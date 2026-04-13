<?php

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    $this->business->users()->attach($this->admin, ['role' => 'admin']);

    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);
});

test('admin can view collaborators list', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/collaborators')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/collaborators/index')
            ->has('collaborators', 1)
        );
});

test('admin can view collaborator detail', function () {
    $this->actingAs($this->admin)
        ->get("/dashboard/settings/collaborators/{$this->collaborator->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/collaborators/show')
            ->has('collaborator')
            ->has('schedule', 7)
            ->has('exceptions')
        );
});

test('admin can invite a collaborator', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/collaborators/invite', [
            'email' => 'new@example.com',
        ])
        ->assertRedirect('/dashboard/settings/collaborators');

    expect(BusinessInvitation::where('email', 'new@example.com')->exists())->toBeTrue();
});

test('cannot invite existing member', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/collaborators/invite', [
            'email' => $this->collaborator->email,
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
        ->post("/dashboard/settings/collaborators/invitations/{$invitation->id}/resend")
        ->assertRedirect();

    expect($invitation->fresh()->token)->not->toBe($oldToken);
});

test('admin can cancel invitation', function () {
    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $this->business->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/collaborators/invitations/{$invitation->id}")
        ->assertRedirect();

    expect(BusinessInvitation::find($invitation->id))->toBeNull();
});

test('admin can update collaborator schedule', function () {
    $rules = collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 3,
        'windows' => $day <= 3 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();

    $this->actingAs($this->admin)
        ->put("/dashboard/settings/collaborators/{$this->collaborator->id}/schedule", [
            'rules' => $rules,
        ])
        ->assertRedirect();

    expect(AvailabilityRule::where('collaborator_id', $this->collaborator->id)->count())->toBe(3);
});

test('admin can add collaborator exception', function () {
    $this->actingAs($this->admin)
        ->post("/dashboard/settings/collaborators/{$this->collaborator->id}/exceptions", [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-01',
            'start_time' => null,
            'end_time' => null,
            'type' => 'block',
            'reason' => 'Sick day',
        ])
        ->assertRedirect();

    $exception = AvailabilityException::where('collaborator_id', $this->collaborator->id)->first();
    expect($exception)->not->toBeNull();
    expect($exception->reason)->toBe('Sick day');
});

test('admin can delete collaborator exception', function () {
    $exception = AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'collaborator_id' => $this->collaborator->id,
    ]);

    $this->actingAs($this->admin)
        ->delete("/dashboard/settings/collaborators/{$this->collaborator->id}/exceptions/{$exception->id}")
        ->assertRedirect();

    expect(AvailabilityException::find($exception->id))->toBeNull();
});

test('admin can toggle collaborator active status', function () {
    expect($this->collaborator->businesses()->first()->pivot->is_active)->toBeTrue();

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/collaborators/{$this->collaborator->id}/toggle-active")
        ->assertRedirect();

    $pivot = $this->business->users()->where('users.id', $this->collaborator->id)->first()->pivot;
    expect($pivot->is_active)->toBeFalse();
});

test('admin can upload collaborator avatar', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post("/dashboard/settings/collaborators/{$this->collaborator->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    expect($this->collaborator->fresh()->avatar)->not->toBeNull();
    Storage::disk('public')->assertExists($this->collaborator->fresh()->avatar);
});

test('cannot manage collaborator from another business', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherCollaborator = User::factory()->create();
    $otherBusiness->users()->attach($otherCollaborator, ['role' => 'collaborator']);

    $this->actingAs($this->admin)
        ->get("/dashboard/settings/collaborators/{$otherCollaborator->id}")
        ->assertForbidden();
});
