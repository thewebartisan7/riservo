<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\Business;
use App\Models\User;
use RuntimeException;

/**
 * Onboarding-wizard helpers. Stub only — implemented by E2E-2.
 *
 * See docs/roadmaps/ROADMAP-E2E.md → Session E2E-2.
 */
final class OnboardingHelper
{
    /**
     * Drive the onboarding wizard for $admin from its current step to completion.
     *
     * @param  array<string, mixed>  $options
     */
    public static function completeWizard(mixed $page, User $admin, array $options = []): Business
    {
        throw new RuntimeException('OnboardingHelper::completeWizard — not yet implemented. See ROADMAP-E2E.md session E2E-2.');
    }

    /**
     * Drive the onboarding wizard for $admin to the requested step and stop.
     */
    public static function advanceToStep(mixed $page, User $admin, int $step): void
    {
        throw new RuntimeException('OnboardingHelper::advanceToStep — not yet implemented. See ROADMAP-E2E.md session E2E-2.');
    }
}
