<?php

declare(strict_types=1);

namespace Tests\Browser\Support\Payments\Pages;

use App\Models\Booking;
use App\Models\BookingRefund;

final class DashboardBookingDetail
{
    public function __construct(private mixed $page) {}

    public function shouldShowPaymentDeeplinkFor(Booking $booking): self
    {
        // D-184: this href mirrors the Wayfinder StripeDashboardLinkController.payment URL.
        $this->assertAnchorHref(route('dashboard.bookings.stripe-link.payment', $booking, false));

        return $this;
    }

    public function shouldShowDisputeDeeplinkFor(Booking $booking): self
    {
        // D-184: this href mirrors the Wayfinder StripeDashboardLinkController.dispute URL.
        $this->assertAnchorHref(route('dashboard.bookings.stripe-link.dispute', $booking, false));

        return $this;
    }

    public function shouldNotExposeRawStripeIds(string ...$ids): self
    {
        foreach ($ids as $id) {
            $this->page->assertSourceMissing($id);
        }

        return $this;
    }

    public function refundsPanel(Booking $booking): RefundsPanel
    {
        return new RefundsPanel($this->page, $booking);
    }

    private function assertAnchorHref(string $href): void
    {
        $encodedHref = json_encode($href, JSON_THROW_ON_ERROR);

        $this->page->assertScript(<<<JS
Array.from(document.querySelectorAll('a')).some((anchor) => anchor.getAttribute('href') === {$encodedHref})
JS);
    }
}

final class RefundsPanel
{
    public function __construct(private mixed $page, private Booking $booking) {}

    public function shouldShowDeeplinkFor(BookingRefund $refund): self
    {
        // D-184: refund rows assert the redirect endpoint and truncated display, never raw stripe_refund_id.
        $href = route('dashboard.bookings.stripe-link.refund', [
            'booking' => $this->booking,
            'refund' => $refund,
        ], false);
        $encodedHref = json_encode($href, JSON_THROW_ON_ERROR);

        $this->page
            ->assertSee(substr((string) $refund->stripe_refund_id, -4))
            ->assertScript(<<<JS
Array.from(document.querySelectorAll('a')).some((anchor) => anchor.getAttribute('href') === {$encodedHref})
JS);

        return $this;
    }
}
