<?php

namespace Database\Factories;

use App\Enums\PendingActionStatus;
use App\Enums\PendingActionType;
use App\Models\Business;
use App\Models\CalendarIntegration;
use App\Models\PendingAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingAction>
 */
class PendingActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'integration_id' => CalendarIntegration::factory(),
            'booking_id' => null,
            'type' => PendingActionType::ExternalBookingConflict,
            'payload' => [],
            'status' => PendingActionStatus::Pending,
        ];
    }

    public function conflict(): static
    {
        return $this->state(fn () => [
            'type' => PendingActionType::ExternalBookingConflict,
        ]);
    }

    public function riservoDeleted(): static
    {
        return $this->state(fn () => [
            'type' => PendingActionType::RiservoEventDeletedInGoogle,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => PendingActionStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'status' => PendingActionStatus::Dismissed,
            'resolved_at' => now(),
        ]);
    }
}
