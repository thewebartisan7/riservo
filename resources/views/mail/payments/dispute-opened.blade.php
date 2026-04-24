<x-mail::message>
# {{ __('A dispute has been opened') }}

{{ __('A customer has disputed a charge on your Stripe account for **:business**. Respond in Stripe before the evidence deadline to submit your case.', ['business' => $businessName]) }}

<x-mail::panel>
**{{ __('Reason') }}:** {{ $reason }}<br>
**{{ __('Amount') }}:** {{ $amountFormatted }}<br>
@if ($evidenceDueBy)
**{{ __('Evidence due by') }}:** {{ $evidenceDueBy }}<br>
@endif
@if ($customerName)
**{{ __('Customer') }}:** {{ $customerName }}<br>
@endif
@if ($customerEmail)
**{{ __('Email') }}:** {{ $customerEmail }}<br>
@endif
@if ($serviceName)
**{{ __('Service') }}:** {{ $serviceName }}<br>
@endif
@if ($bookingStartsAt)
**{{ __('Appointment') }}:** {{ $bookingStartsAt }}
@endif
</x-mail::panel>

{{ __('Riservo does not build an in-app dispute evidence flow. Upload your evidence in the Stripe dashboard; we\'ll update the dashboard banner when the dispute closes.') }}

@if ($stripeDeepLink)
<x-mail::button :url="$stripeDeepLink">
{{ __('Respond in Stripe') }}
</x-mail::button>
@endif

{{ __('Thank you') }},<br>
{{ config('app.name') }}
</x-mail::message>
