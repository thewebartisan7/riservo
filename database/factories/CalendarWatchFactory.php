<?php

namespace Database\Factories;

use App\Models\CalendarIntegration;
use App\Models\CalendarWatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarWatch>
 */
class CalendarWatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => CalendarIntegration::factory(),
            'calendar_id' => 'primary',
            'channel_id' => (string) Str::uuid(),
            'resource_id' => Str::random(32),
            'channel_token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ];
    }
}
