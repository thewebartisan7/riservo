<x-mail::message>
# {{ __('A cancelled booking was paid — refund dispatched') }}

{{ __('A customer at **:business** completed payment after the booking had already been cancelled automatically (the Stripe payment confirmation arrived later than our 90-minute window). An automatic refund has been dispatched. Please reach out to the customer to confirm they received it; their appointment slot may have been re-booked in the meantime.', ['business' => $businessName]) }}

<x-mail::panel>
**{{ __('Customer') }}:** {{ $customerName ?? __('Unknown') }}<br>
@if ($customerEmail)
**{{ __('Email') }}:** {{ $customerEmail }}<br>
@endif
@if ($customerPhone)
**{{ __('Phone') }}:** {{ $customerPhone }}<br>
@endif
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Date') }}:** {{ $date }}<br>
**{{ __('Time') }}:** {{ $time }}<br>
**{{ __('Refunded amount') }}:** {{ $amountFormatted }}
</x-mail::panel>

@if ($stripeRefundId)
**{{ __('Stripe refund id') }}:** `{{ $stripeRefundId }}`
@endif

<x-mail::button :url="$dashboardUrl">
{{ __('Open dashboard') }}
</x-mail::button>

{{ __('Refund attempt reference') }}: #{{ $bookingRefundId }}

{{ __('Thank you') }},<br>
{{ config('app.name') }}
</x-mail::message>
