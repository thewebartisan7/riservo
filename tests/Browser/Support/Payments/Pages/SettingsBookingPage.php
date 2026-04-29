<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\User;
use Tests\Browser\Support\AuthHelper;

final class SettingsBookingPage
{
    public function __construct(private mixed $page) {}

    public static function openAs(User $admin): self
    {
        $page = AuthHelper::loginAs(visit('/login'), $admin)
            ->navigate('/dashboard/settings/booking');

        return new self($page);
    }

    public function shouldShowPaymentModeControls(): self
    {
        $this->page
            ->assertSee('Payment mode')
            ->assertSee('Customers pay on-site')
            ->assertSee('Customers pay when booking')
            ->assertSee('Customers choose at checkout');

        return $this;
    }

    public function shouldShowChCentricUnsupportedCopy(): self
    {
        // D-183: supported_countries flips gate state only; this MVP copy stays CH-centric.
        $this->page->assertSee('Online payments in MVP support CH-located businesses only.');

        return $this;
    }
}
