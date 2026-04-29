<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\Booking;

final class BookingManagementPage
{
    public function __construct(private mixed $page) {}

    public static function open(Booking $booking): self
    {
        return new self(visit('/bookings/'.$booking->cancellation_token));
    }

    public function shouldShowBookingDetails(): self
    {
        $this->page
            ->assertSee('Booking details')
            ->assertNoJavaScriptErrors();

        return $this;
    }

    public function shouldShowPaymentState(string $label): self
    {
        $this->page->assertSee($label);

        return $this;
    }

    public function cancelBooking(): self
    {
        $this->page
            ->assertSee('Cancel booking')
            ->press('Cancel booking');

        return $this;
    }
}
