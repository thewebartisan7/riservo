<?php

namespace App\Services\Calendar\DTOs;

use Carbon\CarbonImmutable;

final readonly class WatchResult
{
    public function __construct(
        public string $channelId,
        public string $resourceId,
        public string $channelToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
