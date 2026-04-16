<?php

use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 5]);
    attachAdmin($this->business, $this->user);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    BusinessHour::factory()->create(['business_id' => $this->business->id, 'day_of_week' => 1]);
});

function attachAdminProviderToService(): void
{
    $provider = attachProvider(test()->business, test()->user);
    $provider->services()->attach(test()->service->id);
}

test('step 5 page renders summary', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/5');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-5')
        ->has('business')
        ->has('hours')
        ->has('service')
        ->has('publicUrl')
    );
});

test('step 5 launch sets onboarding_completed_at', function () {
    attachAdminProviderToService();

    $response = $this->actingAs($this->user)->post('/onboarding/step/5');

    $response->assertRedirect(route('dashboard.welcome'));

    $this->business->refresh();
    expect($this->business->onboarding_completed_at)->not->toBeNull();
    expect($this->business->isOnboarded())->toBeTrue();
});

test('after launch dashboard is accessible', function () {
    attachAdminProviderToService();

    $this->actingAs($this->user)->post('/onboarding/step/5');

    $response = $this->actingAs($this->user)->get('/dashboard');

    $response->assertSuccessful();
});

test('after launch onboarding redirects to dashboard', function () {
    attachAdminProviderToService();

    $this->actingAs($this->user)->post('/onboarding/step/5');

    $response = $this->actingAs($this->user)->get('/onboarding/step/1');

    $response->assertRedirect(route('dashboard'));
});

test('launch blocked when active service has zero providers', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/5');

    $response->assertRedirect(route('onboarding.show', ['step' => 3]))
        ->assertSessionHas('launchBlocked', function (array $data) {
            expect($data['services'])->toHaveCount(1);
            expect($data['services'][0]['id'])->toBe($this->service->id);

            return true;
        });

    $this->business->refresh();
    expect($this->business->onboarding_completed_at)->toBeNull();
});

test('launch blocked lists every unstaffed active service but ignores inactive ones', function () {
    $unstaffedActive = Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);
    Service::factory()->inactive()->create(['business_id' => $this->business->id]);

    $response = $this->actingAs($this->user)->post('/onboarding/step/5');

    $response->assertRedirect(route('onboarding.show', ['step' => 3]))
        ->assertSessionHas('launchBlocked', function (array $data) use ($unstaffedActive) {
            $ids = collect($data['services'])->pluck('id')->all();
            expect($ids)->toContain($this->service->id);
            expect($ids)->toContain($unstaffedActive->id);
            expect($data['services'])->toHaveCount(2);

            return true;
        });
});

test('enable-owner-as-provider creates provider with schedule and service attachments', function () {
    expect(Provider::where('business_id', $this->business->id)->count())->toBe(0);

    $response = $this->actingAs($this->user)->post('/onboarding/enable-owner-as-provider');

    $response->assertRedirect(route('onboarding.show', ['step' => 5]))
        ->assertSessionHas('success');

    $provider = Provider::where('business_id', $this->business->id)
        ->where('user_id', $this->user->id)
        ->firstOrFail();

    expect($provider->trashed())->toBeFalse();
    expect($provider->services()->where('services.id', $this->service->id)->exists())->toBeTrue();
    expect(AvailabilityRule::where('provider_id', $provider->id)->count())->toBeGreaterThan(0);
});

test('enable-owner-as-provider restores a soft-deleted provider row', function () {
    $provider = attachProvider($this->business, $this->user);
    $provider->delete();

    $this->actingAs($this->user)->post('/onboarding/enable-owner-as-provider');

    $provider->refresh();
    expect($provider->trashed())->toBeFalse();
});

test('launch succeeds after enable-owner-as-provider unblocks the gate', function () {
    $this->actingAs($this->user)->post('/onboarding/enable-owner-as-provider');

    $this->actingAs($this->user)
        ->post('/onboarding/step/5')
        ->assertRedirect(route('dashboard.welcome'));

    $this->business->refresh();
    expect($this->business->onboarding_completed_at)->not->toBeNull();
});
