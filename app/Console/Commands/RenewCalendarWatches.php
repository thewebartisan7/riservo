<?php

namespace App\Console\Commands;

use App\Models\CalendarWatch;
use App\Services\Calendar\CalendarProviderFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calendar:renew-watches')]
#[Description('Refresh Google Calendar push-notification watches approaching their expiry')]
class RenewCalendarWatches extends Command
{
    public function handle(CalendarProviderFactory $factory): int
    {
        $threshold = now()->addDay();

        $watches = CalendarWatch::with('integration')
            ->where('expires_at', '<', $threshold)
            ->get();

        if ($watches->isEmpty()) {
            $this->info('No watches due for renewal.');

            return self::SUCCESS;
        }

        $renewed = 0;

        foreach ($watches as $watch) {
            $integration = $watch->integration;

            if ($integration === null || ! $integration->isConfigured()) {
                continue;
            }

            try {
                $provider = $factory->for($integration);

                // Best-effort stop; a dead / already-expired channel must not
                // stop us from creating the new one.
                try {
                    $provider->stopWatch($integration, $watch->channel_id, $watch->resource_id);
                } catch (Throwable $e) {
                    report($e);
                }

                $result = $provider->startWatch($integration, $watch->calendar_id);

                $watch->forceFill([
                    'channel_id' => $result->channelId,
                    'resource_id' => $result->resourceId,
                    'channel_token' => $result->channelToken,
                    'expires_at' => $result->expiresAt,
                ])->save();

                $renewed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Renewed {$renewed} watch(es).");

        return self::SUCCESS;
    }
}
