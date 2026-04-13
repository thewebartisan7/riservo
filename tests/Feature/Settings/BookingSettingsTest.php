<?php

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    $this->business->users()->attach($this->admin, ['role' => 'admin']);
});

test('admin can view booking settings', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/booking')
            ->has('settings')
        );
});

test('admin can update booking settings', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'manual',
            'allow_collaborator_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'round_robin',
            'reminder_hours' => [24, 1],
        ])
        ->assertRedirect('/dashboard/settings/booking');

    $this->business->refresh();
    expect($this->business->confirmation_mode)->toBe(ConfirmationMode::Manual);
    expect($this->business->allow_collaborator_choice)->toBeTrue();
    expect($this->business->cancellation_window_hours)->toBe(24);
    expect($this->business->payment_mode)->toBe(PaymentMode::Offline);
    expect($this->business->assignment_strategy)->toBe(AssignmentStrategy::RoundRobin);
    expect($this->business->reminder_hours)->toBe([24, 1]);
});

test('invalid enum values are rejected', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'invalid',
            'allow_collaborator_choice' => true,
            'cancellation_window_hours' => 24,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionHasErrors('confirmation_mode');
});

test('reminder_hours only accepts valid values', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_collaborator_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [24, 1],
        ])
        ->assertSessionDoesntHaveErrors();
});

test('empty reminder_hours clears reminders', function () {
    $this->business->update(['reminder_hours' => [24, 1]]);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_collaborator_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
            'reminder_hours' => [],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->reminder_hours)->toBe([]);
});

test('missing reminder_hours clears reminders', function () {
    $this->business->update(['reminder_hours' => [24]]);

    // When no checkboxes are checked, the field is absent from form data
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/booking', [
            'confirmation_mode' => 'auto',
            'allow_collaborator_choice' => false,
            'cancellation_window_hours' => 0,
            'payment_mode' => 'offline',
            'assignment_strategy' => 'first_available',
        ])
        ->assertSessionDoesntHaveErrors();

    expect($this->business->fresh()->reminder_hours)->toBe([]);
});
