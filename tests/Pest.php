<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Feature tests render Inertia pages through the `@vite` directive,
        // which tries to resolve `public/build/manifest.json`. CI's Tests
        // job does not run `npm run build` (only the Browser Tests job
        // does), so every Inertia-rendering Feature test would fail with
        // "Vite manifest not found" in CI. `withoutVite()` disables the
        // directive's resolution for the duration of the test — the
        // assertion targets are the Inertia payload + HTTP status, not
        // the rendered script tags. Browser tests opt out of this global
        // by living in their own `->in('Browser')` binding below, where
        // the real manifest is required by the headless browser.
        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->browser()->timeout(10000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function attachStaff(Business $business, User $user): void
{
    $business->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
}

function attachAdmin(Business $business, User $user): void
{
    $business->members()->attach($user, ['role' => BusinessMemberRole::Admin->value]);
}

function attachProvider(Business $business, User $user, bool $active = true): Provider
{
    if (! $business->members()->where('users.id', $user->id)->exists()) {
        $business->members()->attach($user, ['role' => BusinessMemberRole::Staff->value]);
    }

    $provider = Provider::create([
        'business_id' => $business->id,
        'user_id' => $user->id,
    ]);

    if (! $active) {
        $provider->delete();
    }

    return $provider;
}
