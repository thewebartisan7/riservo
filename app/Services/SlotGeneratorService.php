<?php

namespace App\Services;

use App\DTOs\TimeWindow;
use App\Enums\AssignmentStrategy;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SlotGeneratorService
{
    public function __construct(
        private AvailabilityService $availabilityService,
    ) {}

    /**
     * Get available booking slots for a specific provider on a given date.
     *
     * @return array<CarbonImmutable> Slot start times in business timezone
     */
    public function getAvailableSlots(
        Business $business,
        Service $service,
        CarbonImmutable $date,
        ?Provider $provider = null,
    ): array {
        if ($provider) {
            return $this->getSlotsForProvider($business, $service, $provider, $date);
        }

        return $this->getSlotsForAnyProvider($business, $service, $date);
    }

    /**
     * Assign the best provider for a given slot using the business strategy.
     */
    public function assignProvider(
        Business $business,
        Service $service,
        CarbonImmutable $startsAt,
    ): ?Provider {
        $eligible = $this->getEligibleProviders($business, $service);

        if ($eligible->isEmpty()) {
            return null;
        }

        $date = $startsAt->startOfDay();
        $available = $eligible->filter(function (Provider $provider) use ($business, $service, $date, $startsAt) {
            $slots = $this->getSlotsForProvider($business, $service, $provider, $date);

            return collect($slots)->contains(fn (CarbonImmutable $slot) => $slot->eq($startsAt));
        });

        if ($available->isEmpty()) {
            return null;
        }

        $strategy = $business->assignment_strategy ?? AssignmentStrategy::FirstAvailable;

        return match ($strategy) {
            AssignmentStrategy::FirstAvailable => $available->first(),
            AssignmentStrategy::RoundRobin => $this->leastBusyProvider($business, $available),
        };
    }

    /**
     * @return array<CarbonImmutable>
     */
    private function getSlotsForProvider(
        Business $business,
        Service $service,
        Provider $provider,
        CarbonImmutable $date,
    ): array {
        $windows = $this->availabilityService->getAvailableWindows($business, $provider, $date);

        if (empty($windows)) {
            return [];
        }

        $bookings = $this->getBlockingBookings($business, $provider, $date);

        $slots = [];
        foreach ($windows as $window) {
            $windowSlots = $this->generateSlotsFromWindow($window, $service, $bookings);
            $slots = array_merge($slots, $windowSlots);
        }

        return $slots;
    }

    /**
     * @return array<CarbonImmutable>
     */
    private function getSlotsForAnyProvider(
        Business $business,
        Service $service,
        CarbonImmutable $date,
    ): array {
        $eligible = $this->getEligibleProviders($business, $service);

        $seen = [];
        $allSlots = [];

        foreach ($eligible as $provider) {
            $slots = $this->getSlotsForProvider($business, $service, $provider, $date);

            foreach ($slots as $slot) {
                $key = $slot->format('H:i');

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $allSlots[] = $slot;
                }
            }
        }

        usort($allSlots, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->timestamp <=> $b->timestamp);

        return $allSlots;
    }

    /**
     * @return array<CarbonImmutable>
     */
    private function generateSlotsFromWindow(
        TimeWindow $window,
        Service $service,
        Collection $bookings,
    ): array {
        $slots = [];
        $interval = $service->slot_interval_minutes;
        $bufferBefore = $service->buffer_before;
        $bufferAfter = $service->buffer_after;
        $duration = $service->duration_minutes;

        $current = $window->start;

        while (true) {
            $occupiedStart = $current->subMinutes($bufferBefore);
            $occupiedEnd = $current->addMinutes($duration + $bufferAfter);

            // Buffer before must fit within window
            if ($occupiedStart->lt($window->start)) {
                $current = $current->addMinutes($interval);

                continue;
            }

            // Service + buffer after must fit within window
            if ($occupiedEnd->gt($window->end)) {
                break;
            }

            // Check for booking conflicts
            if (! $this->conflictsWithBookings($occupiedStart, $occupiedEnd, $bookings)) {
                $slots[] = $current;
            }

            $current = $current->addMinutes($interval);
        }

        return $slots;
    }

    /**
     * Per D-066, the authoritative occupied interval of an existing booking is the
     * Postgres generated `effective_starts_at` / `effective_ends_at` columns — derived
     * from the snapped `buffer_before_minutes` / `buffer_after_minutes` captured on the
     * booking row, not the live `service->buffer_*` values. Reading the generated
     * columns keeps this method a literal mirror of the `bookings_no_provider_overlap`
     * EXCLUDE GIST constraint. D-030 is why `createFromFormat` with explicit UTC is
     * used instead of Carbon casts.
     */
    private function conflictsWithBookings(
        CarbonImmutable $occupiedStart,
        CarbonImmutable $occupiedEnd,
        Collection $bookings,
    ): bool {
        foreach ($bookings as $booking) {
            $bookingOccupiedStart = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $booking->getRawOriginal('effective_starts_at'),
                'UTC',
            );
            $bookingOccupiedEnd = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $booking->getRawOriginal('effective_ends_at'),
                'UTC',
            );

            // Two intervals overlap if start1 < end2 AND end1 > start2
            if ($occupiedStart->lt($bookingOccupiedEnd) && $occupiedEnd->gt($bookingOccupiedStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function getBlockingBookings(
        Business $business,
        Provider $provider,
        CarbonImmutable $date,
    ): Collection {
        $dayStart = $date->startOfDay()->setTimezone('UTC');
        $dayEnd = $date->endOfDay()->setTimezone('UTC');

        return Booking::where('business_id', $business->id)
            ->where('provider_id', $provider->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->with('service')
            ->get();
    }

    /**
     * @return Collection<int, Provider>
     */
    private function getEligibleProviders(Business $business, Service $service): Collection
    {
        return $service->providers()
            ->where('providers.business_id', $business->id)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Pick the provider with the fewest upcoming confirmed/pending bookings (D-028).
     *
     * @param  Collection<int, Provider>  $candidates
     */
    private function leastBusyProvider(Business $business, Collection $candidates): Provider
    {
        return $candidates->sortBy(function (Provider $provider) use ($business) {
            return Booking::where('business_id', $business->id)
                ->where('provider_id', $provider->id)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->where('starts_at', '>=', now())
                ->count();
        })->first();
    }
}
