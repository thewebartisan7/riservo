<?php

use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->withoutVite();

    config()->set('services.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'http://localhost/dashboard/settings/calendar-integration/callback',
    ]);

    $this->business = Business::factory()->onboarded()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->staff);
});

function fakeSocialiteUser(
    string $id = 'google-user-id',
    string $email = 'owner@example.com',
    ?string $token = 'fake-access-token',
    ?string $refreshToken = 'fake-refresh-token',
    ?int $expiresIn = 3600,
): SocialiteUser {
    $user = new SocialiteUser;
    $user->map([
        'id' => $id,
        'name' => 'Test User',
        'email' => $email,
        'avatar' => null,
    ]);
    $user->token = $token;
    $user->refreshToken = $refreshToken;
    $user->expiresIn = $expiresIn;

    return $user;
}

test('connect redirects to Google with the right scopes and params (native POST)', function () {
    $response = $this->actingAs($this->admin)
        ->from(route('settings.calendar-integration'))
        ->post(route('settings.calendar-integration.connect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('accounts.google.com/o/oauth2/auth');

    $query = [];
    parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $query);

    expect($query)->toHaveKey('scope');
    // Three independent scope assertions so Socialite scope ordering/encoding
    // changes do not falsely fail the test.
    expect($query['scope'])->toContain('openid');
    expect($query['scope'])->toContain('email');
    expect($query['scope'])->toContain('https://www.googleapis.com/auth/calendar.events');

    expect($query)->toHaveKey('access_type');
    expect($query['access_type'])->toBe('offline');
    expect($query)->toHaveKey('prompt');
    expect($query['prompt'])->toBe('consent');
});

test('connect returns Inertia::location when the request is an Inertia visit', function () {
    // Inertia <Form> submits as XHR with X-Inertia: true. A plain 302 to an
    // external URL cannot navigate the browser from XHR, so the controller
    // must respond with Inertia::location(...) for the client to perform a
    // full window.location change.
    $response = $this->actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->from(route('settings.calendar-integration'))
        ->post(route('settings.calendar-integration.connect'));

    $response->assertStatus(409);
    $location = $response->headers->get('X-Inertia-Location');
    expect($location)->not->toBeNull();
    expect($location)->toContain('accounts.google.com/o/oauth2/auth');
});

test('callback persists the integration with tokens encrypted at rest', function () {
    Socialite::fake('google', fakeSocialiteUser(
        email: 'damir@example.com',
        token: 'plaintext-access',
        refreshToken: 'plaintext-refresh',
    ));

    $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration.callback'))
        // MVPC-2: successful callback now redirects to the configure step so
        // the user picks a destination + conflict calendars before sync begins.
        ->assertRedirect(route('settings.calendar-integration.configure'));

    $integration = $this->admin->refresh()->calendarIntegration;
    expect($integration)->not->toBeNull();
    expect($integration->provider)->toBe('google');
    expect($integration->google_account_email)->toBe('damir@example.com');
    expect($integration->token_expires_at)->not->toBeNull();

    // Cast-decrypted read returns plaintext.
    expect($integration->access_token)->toBe('plaintext-access');
    expect($integration->refresh_token)->toBe('plaintext-refresh');

    // Raw DB read must NOT equal the plaintext — proves encryption at rest
    // without coupling to Laravel's envelope format.
    $raw = DB::table('calendar_integrations')->where('id', $integration->id)->first();
    expect($raw->access_token)->not->toBe('plaintext-access');
    expect($raw->refresh_token)->not->toBe('plaintext-refresh');
});

test('callback upserts: a second connect replaces the existing row', function () {
    Socialite::fake('google', fakeSocialiteUser(token: 'first-access', refreshToken: 'first-refresh'));
    $this->actingAs($this->admin)->get(route('settings.calendar-integration.callback'));

    expect(CalendarIntegration::where('user_id', $this->admin->id)->count())->toBe(1);
    expect($this->admin->fresh()->calendarIntegration->access_token)->toBe('first-access');

    Socialite::fake('google', fakeSocialiteUser(token: 'second-access', refreshToken: 'second-refresh'));
    $this->actingAs($this->admin)->get(route('settings.calendar-integration.callback'));

    expect(CalendarIntegration::where('user_id', $this->admin->id)->count())->toBe(1);
    expect($this->admin->fresh()->calendarIntegration->access_token)->toBe('second-access');
    expect($this->admin->fresh()->calendarIntegration->refresh_token)->toBe('second-refresh');
});

test('callback preserves the existing refresh token when Socialite returns null', function () {
    CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
        'provider' => 'google',
        'refresh_token' => 'previously-stored-refresh',
    ]);

    Socialite::fake('google', fakeSocialiteUser(token: 'new-access', refreshToken: null));

    $this->actingAs($this->admin)->get(route('settings.calendar-integration.callback'));

    $integration = $this->admin->fresh()->calendarIntegration;
    expect($integration->access_token)->toBe('new-access');
    expect($integration->refresh_token)->toBe('previously-stored-refresh');
});

test('callback handles Google error query (user denied consent) without crashing', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('settings.calendar-integration'))
        ->assertSessionHas('error');

    expect(CalendarIntegration::where('user_id', $this->admin->id)->exists())->toBeFalse();
});

test('callback redirects with a generic error when Socialite throws', function () {
    // No Socialite::fake → calling ->user() with no valid code or state
    // causes Socialite to throw. The controller must catch and redirect
    // back with a flash error, not 500.
    $response = $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration.callback'));

    $response->assertRedirect(route('settings.calendar-integration'));
    $response->assertSessionHas('error');
    expect(CalendarIntegration::where('user_id', $this->admin->id)->exists())->toBeFalse();
});

test('disconnect deletes the row', function () {
    CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
        'provider' => 'google',
    ]);

    $this->actingAs($this->admin)
        ->delete(route('settings.calendar-integration.disconnect'))
        ->assertRedirect(route('settings.calendar-integration'));

    expect(CalendarIntegration::where('user_id', $this->admin->id)->exists())->toBeFalse();
});

test('disconnect is a no-op when nothing is connected', function () {
    $this->actingAs($this->admin)
        ->delete(route('settings.calendar-integration.disconnect'))
        ->assertRedirect(route('settings.calendar-integration'));

    expect(CalendarIntegration::where('user_id', $this->admin->id)->exists())->toBeFalse();
});

test('admin can view the calendar integration page', function () {
    $response = $this->actingAs($this->admin)->get(route('settings.calendar-integration'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/settings/calendar-integration')
        ->where('connected', false)
        ->where('googleAccountEmail', null)
        ->where('error', null)
    );
});

test('staff can view the calendar integration page', function () {
    $this->actingAs($this->staff)
        ->get(route('settings.calendar-integration'))
        ->assertOk();
});

test('guest is redirected to login', function () {
    $this->get(route('settings.calendar-integration'))
        ->assertRedirect('/login');
});

test('customer-only user is forbidden', function () {
    $customerUser = User::factory()->create(['email_verified_at' => now()]);
    Customer::factory()->create(['user_id' => $customerUser->id]);

    $this->actingAs($customerUser)
        ->get(route('settings.calendar-integration'))
        ->assertForbidden();
});

test('unverified admin is redirected to the verify notice', function () {
    $unverified = User::factory()->unverified()->create();
    attachAdmin($this->business, $unverified);

    $this->actingAs($unverified)
        ->get(route('settings.calendar-integration'))
        ->assertRedirect(route('verification.notice'));
});

test('un-onboarded admin is redirected to onboarding', function () {
    $unboardedBusiness = Business::factory()->create([
        'onboarding_step' => 1,
        'onboarding_completed_at' => null,
    ]);
    $newAdmin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($unboardedBusiness, $newAdmin);

    $response = $this->actingAs($newAdmin)->get(route('settings.calendar-integration'));
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/onboarding/step/');
});

test('connected state exposes the google account email', function () {
    CalendarIntegration::factory()->create([
        'user_id' => $this->admin->id,
        'provider' => 'google',
        'google_account_email' => 'connected@example.com',
    ]);

    $this->actingAs($this->admin)
        ->get(route('settings.calendar-integration'))
        ->assertInertia(fn ($page) => $page
            ->where('connected', true)
            ->where('googleAccountEmail', 'connected@example.com')
        );
});
