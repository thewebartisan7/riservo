<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\StripeConnectedAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StripeConnectedAccount>
 */
class StripeConnectedAccountFactory extends Factory
{
    /**
     * Default state = freshly created Express account, KYC not yet submitted
     * (matches what `accounts.create` returns before the user walks through
     * Stripe-hosted onboarding).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'stripe_account_id' => 'acct_test_'.$this->faker->unique()->lexify('????????????????'),
            'country' => 'CH',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements_currently_due' => [],
            'requirements_disabled_reason' => null,
            'default_currency' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state([
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ]);
    }

    public function incomplete(): self
    {
        return $this->state([
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'requirements_currently_due' => ['external_account', 'tos_acceptance.date'],
        ]);
    }

    public function active(): self
    {
        return $this->state([
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'default_currency' => 'chf',
            'requirements_currently_due' => [],
        ]);
    }

    public function disabled(): self
    {
        return $this->state([
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'requirements_disabled_reason' => 'rejected.fraud',
        ]);
    }
}
