<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\User;
use Tests\Browser\Support\AuthHelper;

final class PayoutsPage
{
    public function __construct(private mixed $page) {}

    public static function openAs(User $admin): self
    {
        $page = AuthHelper::loginAs(visit('/login'), $admin)
            ->navigate('/dashboard/payouts');

        return new self($page);
    }

    public function shouldShowRequirementsCount(int $count): self
    {
        // D-185: assert only the count + generic Stripe CTA, not raw Stripe field paths.
        $this->page->assertSee($count.' requirement(s) due');

        return $this;
    }

    public function openStripeDashboard(): self
    {
        $this->page->click('[data-testid="login-link-button"]');

        return $this;
    }

    public function shouldShowLoginLinkError(string $message): self
    {
        // G-005/D-184 era: the error is rendered from http.errors.login_link.
        $this->page
            ->assertSee($message)
            ->assertPresent('[data-testid="login-link-error"]');

        return $this;
    }

    public function shouldShowUnsupportedMarketBanner(string $country): self
    {
        $this->page
            ->assertPresent('[data-testid="unsupported-market-banner"]')
            ->assertSee($country);

        return $this;
    }
}
