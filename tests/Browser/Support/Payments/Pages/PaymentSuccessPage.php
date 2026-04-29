<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\Booking;

final class PaymentSuccessPage
{
    public function __construct(private mixed $page) {}

    public static function open(Booking $booking, string $sessionId): self
    {
        return new self(visit("/bookings/{$booking->cancellation_token}/payment-success?session_id={$sessionId}"));
    }

    public function shouldShowProcessingState(): self
    {
        $this->page
            ->assertSee('Processing payment')
            ->assertSee('Check booking status')
            ->assertNoJavaScriptErrors();

        return $this;
    }

    public function followBookingStatusLink(): BookingManagementPage
    {
        $this->page->click('Check booking status');

        return new BookingManagementPage($this->page);
    }
}
