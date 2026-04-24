<x-mail::message>
# {{ __('Refund could not be processed automatically') }}

{{ __('An automatic refund on a booking at **:business** failed. This usually means the Stripe connected account is no longer authorised — for example, the business disconnected Stripe or Stripe suspended the account. The customer is waiting for their money back.', ['business' => $businessName]) }}

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
**{{ __('Amount') }}:** {{ $amountFormatted }}
</x-mail::panel>

**{{ __('Stripe error') }}:** {{ $failureReason }}

{{ __('Next steps: reconnect Stripe so the refund can be retried, or refund the customer offline and mark the Pending Action resolved in the dashboard.') }}

<x-mail::button :url="$dashboardUrl">
{{ __('Open dashboard') }}
</x-mail::button>

{{ __('Refund attempt reference') }}: #{{ $bookingRefundId }}

{{ __('Thank you') }},<br>
{{ config('app.name') }}
</x-mail::message>
