<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarIntegration>
 */
class CalendarIntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'access_token' => Str::random(64),
            'refresh_token' => Str::random(64),
            'token_expires_at' => now()->addHour(),
            'calendar_id' => null,
            'google_account_email' => fake()->safeEmail(),
            // MVPC-1 rows default to unconfigured. `configured()` finalises.
            'business_id' => null,
            'destination_calendar_id' => null,
            'conflict_calendar_ids' => null,
        ];
    }

    /**
     * Finalise the integration as if the MVPC-2 configure step ran.
     */
    public function configured(?int $businessId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'business_id' => $businessId ?? Business::factory(),
            'destination_calendar_id' => 'primary',
            'conflict_calendar_ids' => ['primary'],
        ]);
    }
}
