<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\Business;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;

final class BookingPage
{
    public function __construct(private mixed $page) {}

    public static function open(Business $business): self
    {
        return new self(visit('/'.$business->slug));
    }

    public function chooseService(Service $service): self
    {
        $this->page
            ->assertSee($service->name)
            ->click($service->name);

        return $this;
    }

    public function chooseAnySpecialist(): self
    {
        $this->page
            ->assertSee('Any specialist')
            ->click('Any specialist');

        return $this;
    }

    public function chooseDateAndTime(CarbonImmutable $targetDate, string $time = '09:00'): self
    {
        BookingFlowHelper::selectDateAndTime($this->page, $targetDate, $time);

        return $this;
    }

    /**
     * @param  array{name: string, email: string, phone: string, notes?: string}  $details
     */
    public function fillCustomerDetails(array $details): self
    {
        $this->page
            ->assertSee('Just a few details')
            ->type('name', $details['name'])
            ->type('email', $details['email'])
            ->type('phone', $details['phone']);

        return $this;
    }

    public function continueToSummary(): BookingSummaryStep
    {
        $this->page->script("Array.from(document.querySelectorAll('button[type=\"submit\"]')).find(b => (b.textContent || '').includes('Continue'))?.click();");

        return new BookingSummaryStep($this->page);
    }

    public function page(): mixed
    {
        return $this->page;
    }
}
