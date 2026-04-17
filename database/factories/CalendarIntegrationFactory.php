<?php

namespace Database\Factories;

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
            'webhook_channel_id' => Str::uuid()->toString(),
            'webhook_expiry' => now()->addDays(7),
        ];
    }
}
