<?php

namespace App\Services\Calendar\DTOs;

final readonly class SyncResult
{
    /**
     * @param  array<int, ExternalEvent>  $events  all events returned by the incremental sync (the job differentiates create/update/cancel by ExternalEvent::$status and by existing DB state)
     */
    public function __construct(
        public array $events,
        public ?string $nextSyncToken,
    ) {}
}
