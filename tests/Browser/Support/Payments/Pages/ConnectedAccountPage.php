<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\StripeConnectedAccount;
use App\Models\User;
use Tests\Browser\Support\AuthHelper;

final class ConnectedAccountPage
{
    public function __construct(private mixed $page) {}

    public static function openAs(User $admin): self
    {
        $page = AuthHelper::loginAs(visit('/login'), $admin)
            ->navigate('/dashboard/settings/connected-account');

        return new self($page);
    }

    public function shouldShowActiveState(StripeConnectedAccount $account): self
    {
        $this->page
            ->assertPathIs('/dashboard/settings/connected-account')
            ->assertSee('Verified')
            ->assertSee('Your Stripe account is verified.')
            ->assertSee('Charges')
            ->assertSee('Payouts')
            ->assertSee(substr($account->stripe_account_id, -4))
            ->assertNoJavaScriptErrors();

        return $this;
    }

    public function shouldShowRequirementsCount(int $count): self
    {
        // D-185: assert only count + Stripe CTA, never a list of raw Stripe requirement paths.
        $this->page
            ->assertSee($count.' item pending')
            ->assertSee('Continue in Stripe');

        return $this;
    }

    public function shouldShowUnsupportedMarketState(string $country): self
    {
        $this->page
            ->assertSee('Online payments not available for your country yet')
            ->assertSee($country)
            ->assertSee('Contact support');

        return $this;
    }

    public function clickEnableOnlinePayments(): self
    {
        $this->page->click('Enable online payments');

        return $this;
    }

    public function page(): mixed
    {
        return $this->page;
    }
}
