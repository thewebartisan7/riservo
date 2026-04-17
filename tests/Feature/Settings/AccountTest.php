<?php

use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create([
        'email_verified_at' => now(),
        'password' => Hash::make('current-password-1'),
    ]);
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create([
        'email_verified_at' => now(),
        'password' => Hash::make('staff-password-1'),
    ]);
    attachStaff($this->business, $this->staff);
});

test('admin can view account page', function () {
    $response = $this->actingAs($this->admin)->get('/dashboard/settings/account');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/settings/account')
        ->where('isAdmin', true)
        ->where('hasPassword', true)
        ->where('isProvider', false)
        ->where('hasProviderRow', false)
        ->where('user.name', $this->admin->name)
        ->where('user.email', $this->admin->email)
    );
});

test('staff can view account page', function () {
    $response = $this->actingAs($this->staff)->get('/dashboard/settings/account');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/settings/account')
        ->where('isAdmin', false)
        ->where('hasPassword', true)
        ->where('isProvider', false)
    );
});

test('account page reports hasPassword false for magic-link-only users', function () {
    $magicOnly = User::factory()->create([
        'email_verified_at' => now(),
        'password' => null,
    ]);
    attachStaff($this->business, $magicOnly);

    $this->actingAs($magicOnly)
        ->get('/dashboard/settings/account')
        ->assertInertia(fn ($page) => $page->where('hasPassword', false));
});

test('admin can update profile name', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/profile', [
            'name' => 'New Name',
            'email' => $this->admin->email,
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($this->admin->fresh()->name)->toBe('New Name');
});

test('staff can update their own profile', function () {
    $this->actingAs($this->staff)
        ->put('/dashboard/settings/account/profile', [
            'name' => 'Staff Renamed',
            'email' => $this->staff->email,
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($this->staff->fresh()->name)->toBe('Staff Renamed');
});

test('email change nulls verified_at and dispatches verification notification', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/profile', [
            'name' => $this->admin->name,
            'email' => 'new-address@example.com',
        ])
        ->assertRedirect('/dashboard/settings/account')
        ->assertSessionHas('success');

    $fresh = $this->admin->fresh();
    expect($fresh->email)->toBe('new-address@example.com');
    expect($fresh->email_verified_at)->toBeNull();

    Notification::assertSentTo($fresh, VerifyEmail::class);
});

test('saving profile without changing email does NOT re-verify', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/profile', [
            'name' => 'Same Email Renamed',
            'email' => $this->admin->email,
        ])
        ->assertRedirect('/dashboard/settings/account');

    expect($this->admin->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('email change rejects an address already used by another user', function () {
    $other = User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/profile', [
            'name' => $this->admin->name,
            'email' => 'taken@example.com',
        ])
        ->assertSessionHasErrors('email');

    expect($this->admin->fresh()->email)->not->toBe('taken@example.com');
});

test('change password requires current_password when one is set', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/password', [
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('new-password-2', $this->admin->fresh()->password))->toBeFalse();
});

test('change password rejects wrong current_password', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ])
        ->assertSessionHasErrors('current_password');
});

test('change password succeeds with correct current_password', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/account/password', [
            'current_password' => 'current-password-1',
            'password' => 'new-password-2',
            'password_confirmation' => 'new-password-2',
        ])
        ->assertRedirect('/dashboard/settings/account')
        ->assertSessionHas('success');

    expect(Hash::check('new-password-2', $this->admin->fresh()->password))->toBeTrue();
});

test('magic-link-only user can set first password without current_password', function () {
    $magicOnly = User::factory()->create([
        'email_verified_at' => now(),
        'password' => null,
    ]);
    attachStaff($this->business, $magicOnly);

    $this->actingAs($magicOnly)
        ->put('/dashboard/settings/account/password', [
            'password' => 'first-password-1',
            'password_confirmation' => 'first-password-1',
        ])
        ->assertRedirect('/dashboard/settings/account');

    $fresh = $magicOnly->fresh();
    expect($fresh->password)->not->toBeNull();
    expect(Hash::check('first-password-1', $fresh->password))->toBeTrue();
});

test('after first password is set, subsequent change requires current_password', function () {
    $magicOnly = User::factory()->create([
        'email_verified_at' => now(),
        'password' => null,
    ]);
    attachStaff($this->business, $magicOnly);

    $this->actingAs($magicOnly)
        ->put('/dashboard/settings/account/password', [
            'password' => 'first-password-1',
            'password_confirmation' => 'first-password-1',
        ])
        ->assertRedirect('/dashboard/settings/account');

    // Without current_password — must now be rejected.
    $this->actingAs($magicOnly->fresh())
        ->put('/dashboard/settings/account/password', [
            'password' => 'second-password-2',
            'password_confirmation' => 'second-password-2',
        ])
        ->assertSessionHasErrors('current_password');
});

test('admin can upload an avatar', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('me.jpg', 200, 200);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/avatar', ['avatar' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    $fresh = $this->admin->fresh();
    expect($fresh->avatar)->not->toBeNull();
    Storage::disk('public')->assertExists($fresh->avatar);
});

test('uploading a new avatar deletes the old file', function () {
    Storage::fake('public');

    $first = UploadedFile::fake()->image('first.jpg');
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/avatar', ['avatar' => $first]);
    $firstPath = $this->admin->fresh()->avatar;

    $second = UploadedFile::fake()->image('second.jpg');
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/avatar', ['avatar' => $second]);

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($this->admin->fresh()->avatar);
});

test('avatar upload validates image type', function () {
    Storage::fake('public');

    $bad = UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain');

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/avatar', ['avatar' => $bad])
        ->assertSessionHasErrors('avatar');
});

test('avatar remove deletes file and nulls user.avatar', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

    $path = $this->admin->fresh()->avatar;
    expect($path)->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete('/dashboard/settings/account/avatar')
        ->assertRedirect('/dashboard/settings/account');

    $fresh = $this->admin->fresh();
    expect($fresh->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('admin can toggle bookable provider on', function () {
    Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->admin->id)
        ->firstOrFail();

    expect($provider->trashed())->toBeFalse();
    expect($provider->services()->count())->toBe(1);
});

test('toggle off warns when leaving an active service without a provider', function () {
    $provider = attachProvider($this->business, $this->admin);
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);
    $provider->services()->attach($service->id);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account')
        ->assertSessionHas('warning');
});

test('toggle restores a soft-deleted provider with prior attachments', function () {
    $provider = attachProvider($this->business, $this->admin);
    $service = Service::factory()->create(['business_id' => $this->business->id]);
    $provider->services()->attach($service->id);
    $provider->delete();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertRedirect('/dashboard/settings/account');

    $provider->refresh();
    expect($provider->trashed())->toBeFalse();
    expect($provider->services()->where('services.id', $service->id)->exists())->toBeTrue();
});
