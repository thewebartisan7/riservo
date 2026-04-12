<?php

namespace App\Services;

use App\DTOs\TimeWindow;
use App\Enums\AssignmentStrategy;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SlotGeneratorService
{
    public function __construct(
        private AvailabilityService $availabilityService,
    ) {}

    /**
     * Get available booking slots for a specific collaborator on a given date.
     *
     * @return array<CarbonImmutable> Slot start times in business timezone
     */
    public function getAvailableSlots(
        Business $business,
        Service $service,
        CarbonImmutable $date,
        ?User $collaborator = null,
    ): array {
        if ($collaborator) {
            return $this->getSlotsForCollaborator($business, $service, $collaborator, $date);
        }

        return $this->getSlotsForAnyCollaborator($business, $service, $date);
    }

    /**
     * Assign the best collaborator for a given slot using the business strategy.
     */
    public function assignCollaborator(
        Business $business,
        Service $service,
        CarbonImmutable $startsAt,
    ): ?User {
        $eligible = $this->getEligibleCollaborators($business, $service);

        if ($eligible->isEmpty()) {
            return null;
        }

        $date = $startsAt->startOfDay();
        $available = $eligible->filter(function (User $collaborator) use ($business, $service, $date, $startsAt) {
            $slots = $this->getSlotsForCollaborator($business, $service, $collaborator, $date);

            return collect($slots)->contains(fn (CarbonImmutable $slot) => $slot->eq($startsAt));
        });

        if ($available->isEmpty()) {
            return null;
        }

        $strategy = $business->assignment_strategy ?? AssignmentStrategy::FirstAvailable;

        return match ($strategy) {
            AssignmentStrategy::FirstAvailable => $available->first(),
            AssignmentStrategy::RoundRobin => $this->leastBusyCollaborator($business, $available),
        };
    }

    /**
     * @return array<CarbonImmutable>
     */
    private function getSlotsForCollaborator(
        Business $business,
        Service $service,
        User $collaborator,
        CarbonImmutable $date,
    ): array {
        $windows = $this->availabilityService->getAvailableWindows($business, $collaborator, $date);

        if (empty($windows)) {
            return [];
        }

        $bookings = $this->getBlockingBookings($business, $collaborator, $date);

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
    private function getSlotsForAnyCollaborator(
        Business $business,
        Service $service,
        CarbonImmutable $date,
    ): array {
        $eligible = $this->getEligibleCollaborators($business, $service);

        $seen = [];
        $allSlots = [];

        foreach ($eligible as $collaborator) {
            $slots = $this->getSlotsForCollaborator($business, $service, $collaborator, $date);

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

    private function conflictsWithBookings(
        CarbonImmutable $occupiedStart,
        CarbonImmutable $occupiedEnd,
        Collection $bookings,
    ): bool {
        foreach ($bookings as $booking) {
            $bookingBufferBefore = $booking->service->buffer_before ?? 0;
            $bookingBufferAfter = $booking->service->buffer_after ?? 0;

            $bookingOccupiedStart = CarbonImmutable::parse($booking->starts_at)->subMinutes($bookingBufferBefore);
            $bookingOccupiedEnd = CarbonImmutable::parse($booking->ends_at)->addMinutes($bookingBufferAfter);

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
        User $collaborator,
        CarbonImmutable $date,
    ): Collection {
        $dayStart = $date->startOfDay()->setTimezone('UTC');
        $dayEnd = $date->endOfDay()->setTimezone('UTC');

        return Booking::where('business_id', $business->id)
            ->where('collaborator_id', $collaborator->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->with('service')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function getEligibleCollaborators(Business $business, Service $service): Collection
    {
        return $service->collaborators()
            ->wherePivot('service_id', $service->id)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Pick the collaborator with the fewest upcoming confirmed/pending bookings (D-028).
     *
     * @param  Collection<int, User>  $candidates
     */
    private function leastBusyCollaborator(Business $business, Collection $candidates): User
    {
        return $candidates->sortBy(function (User $collaborator) use ($business) {
            return Booking::where('business_id', $business->id)
                ->where('collaborator_id', $collaborator->id)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->where('starts_at', '>=', now())
                ->count();
        })->first();
    }
}
