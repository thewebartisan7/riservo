<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Onboarding-wizard helpers for E2E-2 browser tests.
 *
 * These helpers drive the wizard via UI actions where practical, and fall back
 * to direct model seeding for steps whose widgets (COSS UI Select without a
 * `name` attribute on hour pickers) are impractical to drive through the
 * browser. Every helper leaves the database in a state consistent with what
 * the OnboardingController would have produced — business rows advance, hours
 * insert, services create, and the owner can be pre-seeded as a provider when
 * requested.
 *
 * See docs/roadmaps/ROADMAP-E2E.md → Session E2E-2.
 */
final class OnboardingHelper
{
    /**
     * Drive the wizard for $admin from its current step through to successful
     * launch. After this returns, the business is onboarded (onboarding_step=5,
     * onboarding_completed_at=now()) and the caller's browser is on
     * `/dashboard/welcome`.
     *
     * Supported options:
     *   - 'slug' (string)  override the slug typed in Step 1.
     *   - 'service_name' (string)  name typed in Step 3. Defaults to 'Haircut'.
     *   - 'duration_minutes' (int) typed in Step 3. Defaults to 60.
     *   - 'opt_in_owner' (bool)  true = opt the admin in as a provider.
     *                            Defaults to true, which matches the only path
     *                            that can launch without staff invites.
     *   - 'invitations' (array<string>) staff emails to invite in Step 4.
     *
     * @param  array<string, mixed>  $options
     */
    public static function completeWizard(mixed $page, User $admin, array $options = []): Business
    {
        $business = self::businessFor($admin);

        if ($business->isOnboarded()) {
            return $business;
        }

        // Step 1 — Profile.
        if ($business->onboarding_step <= 1) {
            $page = self::submitProfileStep($page, $business, $options);
            $business->refresh();
        }

        // Step 2 — Business hours.
        if ($business->onboarding_step <= 2) {
            self::seedWeeklyBusinessHours($business);
            self::advanceBusinessStep($business, 3);
            $page->navigate('/onboarding/step/3');
            $business->refresh();
        }

        // Step 3 — Service + owner-as-provider opt-in.
        if ($business->onboarding_step <= 3) {
            $page = self::submitServiceStep($page, $business, $admin, $options);
            $business->refresh();
        }

        // Step 4 — Staff invites (skip unless overridden).
        if ($business->onboarding_step <= 4) {
            $page = self::submitInvitationsStep($page, $options);
            $business->refresh();
        }

        // Step 5 — Launch. Button label includes a trailing arrow glyph.
        $page->click('Launch your booking page →');

        $business->refresh();

        return $business;
    }

    /**
     * Seed DB state so `$admin`'s business is ready to re-enter `/onboarding/step/{step}`.
     *
     * Supported steps: 1..5. This helper does **not** drive the browser — the
     * caller is expected to issue `visit('/onboarding/step/{step}')` after
     * authenticating as the admin.
     *
     * Each prior step's side effects are written to the DB so the requested
     * step renders with live data: business hours exist for step 3, a service
     * exists for step 4, and `onboarding_step` is advanced accordingly.
     *
     * The `$page` argument is retained for signature compatibility with the
     * E2E-0 stub; callers may pass `null` when they don't already have a
     * page in hand.
     */
    public static function advanceToStep(mixed $page, User $admin, int $step): void
    {
        if ($step < 1 || $step > 5) {
            throw new InvalidArgumentException("Onboarding step must be between 1 and 5; got {$step}.");
        }

        $business = self::businessFor($admin);

        // Seed each prior step's side effects idempotently, even if the caller
        // pre-set `onboarding_step` forward. The goal is: when the browser
        // visits `/onboarding/step/$step`, the DB has everything the page
        // expects to render (hours for step 3, a service for step 4, etc.).

        if ($step > 2) {
            self::seedWeeklyBusinessHours($business);
        }

        if ($step > 3) {
            self::seedService($business);
        }

        self::advanceBusinessStep($business, $step);
        $business->refresh();

        if ($page !== null) {
            $page->navigate('/onboarding/step/'.$step);
        }
    }

    // ---------- Internal helpers ----------

    private static function businessFor(User $admin): Business
    {
        /** @var Business|null $business */
        $business = $admin->businesses()->first();

        if (! $business) {
            throw new InvalidArgumentException(
                'User #'.$admin->id.' is not a member of any business — attach via attachAdmin() first.'
            );
        }

        return $business;
    }

    private static function advanceBusinessStep(Business $business, int $step): void
    {
        if ($business->onboarding_step < $step) {
            $business->update(['onboarding_step' => $step]);
        }
    }

    private static function seedWeeklyBusinessHours(Business $business): void
    {
        if ($business->businessHours()->exists()) {
            return;
        }

        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::factory()->for($business)->create([
                'day_of_week' => $day,
                'open_time' => '09:00',
                'close_time' => '18:00',
            ]);
        }
    }

    private static function seedService(Business $business, string $name = 'Haircut'): Service
    {
        $existing = $business->services()->first();

        if ($existing) {
            return $existing;
        }

        return Service::factory()->for($business)->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'duration_minutes' => 60,
            'slot_interval_minutes' => 30,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function submitProfileStep(mixed $page, Business $business, array $options): mixed
    {
        $page->navigate('/onboarding/step/1');

        // The business name is pre-filled by the controller; submit the form
        // using the already-rendered values plus any overrides. We press by
        // visible label rather than form submit() because the button is inside
        // Inertia's <Form> render-props component.
        if (isset($options['slug'])) {
            $page->clear('slug')->type('slug', (string) $options['slug']);
        }

        return $page->press('Continue to hours');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function submitServiceStep(mixed $page, Business $business, User $admin, array $options): mixed
    {
        $serviceName = (string) ($options['service_name'] ?? 'Haircut');
        $optInOwner = (bool) ($options['opt_in_owner'] ?? true);

        // Write service + provider directly. The widgets on this step (price
        // InputGroup, NumberField for duration, COSS Select for slot interval,
        // and WeekScheduleEditor) don't expose reliable name attributes for
        // browser drivers; seeding models matches controller output exactly.
        $service = self::seedService($business, $serviceName);

        if ($optInOwner) {
            $provider = Provider::withTrashed()
                ->where('business_id', $business->id)
                ->where('user_id', $admin->id)
                ->first();

            if ($provider) {
                if ($provider->trashed()) {
                    $provider->restore();
                }
            } else {
                $provider = attachProvider($business, $admin);
            }

            foreach ([1, 2, 3, 4, 5] as $day) {
                $provider->availabilityRules()->firstOrCreate(
                    ['day_of_week' => $day],
                    [
                        'business_id' => $business->id,
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                    ]
                );
            }

            $provider->services()->syncWithoutDetaching([$service->id]);
        }

        self::advanceBusinessStep($business, 4);

        $page->navigate('/onboarding/step/4');

        return $page;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function submitInvitationsStep(mixed $page, array $options): mixed
    {
        $emails = (array) ($options['invitations'] ?? []);

        if (empty($emails)) {
            // First row is empty → label is "Continue without inviting".
            return $page->press('Continue without inviting');
        }

        // The first row is rendered with no initial value; fill it and add
        // additional rows for each remaining invitation.
        foreach ($emails as $index => $email) {
            if ($index > 0) {
                $page->click('Add another person');
            }

            // Emails use a bare <Input type="email"> with no `name` attr, and
            // the component tracks state internally. Type into the index-th
            // matching input by CSS selector.
            $page->type('input[type="email"]:nth-of-type('.($index + 1).')', (string) $email);
        }

        return $page->press('Send invites & continue');
    }
}
