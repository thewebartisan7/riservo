<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Rules\BelongsToCurrentBusiness;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->business = Business::factory()->create();
    $this->otherBusiness = Business::factory()->create();

    // Pin the TenantContext explicitly for unit-style validation.
    app(TenantContext::class)->set($this->business, BusinessMemberRole::Admin);
});

test('in-tenant id passes', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    $validator = Validator::make(
        ['service_id' => $service->id],
        ['service_id' => [new BelongsToCurrentBusiness(Service::class)]],
    );

    expect($validator->fails())->toBeFalse();
});

test('cross-tenant id fails', function () {
    $foreign = Service::factory()->create(['business_id' => $this->otherBusiness->id]);

    $validator = Validator::make(
        ['service_id' => $foreign->id],
        ['service_id' => [new BelongsToCurrentBusiness(Service::class)]],
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('service_id'))
        ->toBe(__('The selected service is invalid.'));
});

test('soft-deleted in-tenant provider fails by default', function () {
    $user = User::factory()->create();
    $provider = attachProvider($this->business, $user);
    $provider->delete();

    $validator = Validator::make(
        ['provider_id' => $provider->id],
        ['provider_id' => [new BelongsToCurrentBusiness(Provider::class)]],
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('provider_id'))
        ->toBe(__('The selected provider is invalid.'));
});

test('missing tenant context fails with invalid tenant message', function () {
    $service = Service::factory()->create(['business_id' => $this->business->id]);

    app(TenantContext::class)->clear();

    $validator = Validator::make(
        ['service_id' => $service->id],
        ['service_id' => [new BelongsToCurrentBusiness(Service::class)]],
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('service_id'))
        ->toBe(__('Invalid business context.'));
});

test('null value passes (nullable FKs skip the rule)', function () {
    $validator = Validator::make(
        ['provider_id' => null],
        ['provider_id' => [new BelongsToCurrentBusiness(Provider::class)]],
    );

    expect($validator->fails())->toBeFalse();
});

test('column override works for non-id lookups', function () {
    $service = Service::factory()->create([
        'business_id' => $this->business->id,
        'slug' => 'custom-slug',
    ]);

    $validator = Validator::make(
        ['service_slug' => 'custom-slug'],
        ['service_slug' => [new BelongsToCurrentBusiness(Service::class, 'slug')]],
    );

    expect($validator->fails())->toBeFalse();
});
