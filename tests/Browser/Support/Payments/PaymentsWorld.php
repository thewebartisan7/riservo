<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments;

use App\Enums\PaymentMode;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Service;
use App\Models\StripeConnectedAccount;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

final class PaymentsWorld
{
    private ?string $stripeAccountState = null;

    /** @var array<string, mixed> */
    private array $stripeAccountOverrides = [];

    private ?PaymentMode $paymentMode = null;

    public static function default(): self
    {
        return new self;
    }

    public function withActiveStripeAccount(array $overrides = []): self
    {
        $this->stripeAccountState = 'active';
        $this->stripeAccountOverrides = $overrides;

        return $this;
    }

    public function withIncompleteStripeAccount(int $requirementsCount = 2, array $overrides = []): self
    {
        $this->stripeAccountState = 'incomplete';
        $this->stripeAccountOverrides = array_merge([
            'requirements_currently_due' => $this->requirements($requirementsCount),
        ], $overrides);

        return $this;
    }

    public function withPendingStripeAccount(array $overrides = []): self
    {
        $this->stripeAccountState = 'pending';
        $this->stripeAccountOverrides = $overrides;

        return $this;
    }

    public function withDisabledStripeAccount(array $overrides = []): self
    {
        $this->stripeAccountState = 'disabled';
        $this->stripeAccountOverrides = $overrides;

        return $this;
    }

    public function withOnlinePaymentMode(): self
    {
        $this->paymentMode = PaymentMode::Online;

        return $this;
    }

    public function withCustomerChoicePaymentMode(): self
    {
        $this->paymentMode = PaymentMode::CustomerChoice;

        return $this;
    }

    /**
     * @return array{
     *     business: Business,
     *     admin: User,
     *     provider: Provider,
     *     service: Service,
     *     customer: Customer,
     *     connectedAccount: StripeConnectedAccount|null
     * }
     */
    public function build(): array
    {
        /** @var array{business: Business, admin: User, provider: Provider, service: Service, customer: Customer} $world */
        $world = BusinessSetup::createLaunchedBusiness([
            'payment_mode' => $this->paymentMode ?? PaymentMode::Offline,
        ]);

        $connectedAccount = $this->buildConnectedAccount($world['business']);
        $world['business']->refresh();

        return [
            'business' => $world['business'],
            'admin' => $world['admin'],
            'provider' => $world['provider'],
            'service' => $world['service'],
            'customer' => $world['customer'],
            'connectedAccount' => $connectedAccount,
        ];
    }

    private function buildConnectedAccount(Business $business): ?StripeConnectedAccount
    {
        if ($this->stripeAccountState === null) {
            return null;
        }

        $factory = StripeConnectedAccount::factory()->for($business);

        $factory = match ($this->stripeAccountState) {
            'active' => $factory->active(),
            'incomplete' => $factory->incomplete(),
            'pending' => $factory->pending(),
            'disabled' => $factory->disabled(),
            default => $factory,
        };

        return $factory->state($this->stripeAccountOverrides)->create();
    }

    /**
     * D-185: browser page objects assert a count + CTA, never raw Stripe field paths.
     *
     * @return list<string>
     */
    private function requirements(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(
            static fn (int $number): string => 'browser_requirement_'.$number,
            range(1, $count),
        );
    }
}
