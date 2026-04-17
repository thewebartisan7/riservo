<?php

namespace App\Services\Calendar\DTOs;

use Carbon\CarbonImmutable;

final readonly class ExternalEvent
{
    /**
     * @param  array<int, string>  $attendeeEmails
     * @param  array<string, string>  $extendedProperties  private extendedProperties keyed by name
     */
    public function __construct(
        public string $id,
        public string $calendarId,
        public string $status,
        public ?string $summary,
        public ?string $description,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public array $attendeeEmails,
        public ?string $htmlLink,
        public array $extendedProperties,
        public ?string $creatorEmail,
    ) {}

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function riservoBookingId(): ?int
    {
        $value = $this->extendedProperties['riservo_booking_id'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function riservoBusinessId(): ?int
    {
        $value = $this->extendedProperties['riservo_business_id'] ?? null;

        return $value !== null ? (int) $value : null;
    }
}
