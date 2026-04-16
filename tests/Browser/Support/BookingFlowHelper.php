<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use RuntimeException;

/**
 * Public-booking-flow helpers. Stub only — implemented by E2E-3.
 *
 * See docs/roadmaps/ROADMAP-E2E.md → Session E2E-3.
 */
final class BookingFlowHelper
{
    /**
     * Drive the public booking funnel as a guest and return the management
     * token displayed on the confirmation screen.
     *
     * @param  array<string, mixed>  $customerDetails
     */
    public static function bookAsGuest(mixed $page, Business $business, Service $service, array $customerDetails = []): string
    {
        throw new RuntimeException('BookingFlowHelper::bookAsGuest — not yet implemented. See ROADMAP-E2E.md session E2E-3.');
    }

    /**
     * Drive the public booking funnel as an already-logged-in customer and
     * return the resulting booking's management token.
     */
    public static function bookAsRegistered(mixed $page, Business $business, Service $service, Customer $customer): string
    {
        throw new RuntimeException('BookingFlowHelper::bookAsRegistered — not yet implemented. See ROADMAP-E2E.md session E2E-3.');
    }
}
