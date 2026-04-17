<?php

namespace App\Services\Calendar\DTOs;

final readonly class CalendarSummary
{
    public function __construct(
        public string $id,
        public string $summary,
        public bool $primary,
        public string $accessRole,
    ) {}
}
