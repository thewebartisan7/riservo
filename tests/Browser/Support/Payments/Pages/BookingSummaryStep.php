<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

final class BookingSummaryStep
{
    public function __construct(private mixed $page) {}

    public function shouldShowTwintBadge(): self
    {
        $this->page
            ->assertSee('Secured by Stripe')
            ->assertSee('TWINT');

        return $this;
    }

    public function choosePayNow(): self
    {
        $this->page->click('Pay now');

        return $this;
    }

    public function choosePayOnSite(): self
    {
        $this->page->click('Pay on site');

        return $this;
    }

    public function confirm(): self
    {
        $this->page->script("Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('Confirm booking') || (b.textContent || '').includes('Continue to payment'))?.click();");

        return $this;
    }

    public function shouldShowUseHttpError(string $message): self
    {
        // useHttp errors render through the Inertia validation envelope, not a custom toast.
        $this->page->assertSee($message);

        return $this;
    }

    public function page(): mixed
    {
        return $this->page;
    }
}
