<x-mail::message>
# {{ __('Dispute resolved — :outcome', ['outcome' => $outcomeLabel]) }}

@if ($stripeStatus === 'won')
{{ __('The dispute on **:business** was resolved in your favour. The funds stay with you.', ['business' => $businessName]) }}
@elseif ($stripeStatus === 'lost')
{{ __('The dispute on **:business** was resolved in the customer\'s favour — the disputed funds have been returned to them by Stripe.', ['business' => $businessName]) }}
@else
{{ __('The dispute on **:business** has been closed. The outcome reported by Stripe was ":status".', ['business' => $businessName, 'status' => $stripeStatus]) }}
@endif

@if ($customerName)
<x-mail::panel>
**{{ __('Customer') }}:** {{ $customerName }}<br>
@if ($serviceName)
**{{ __('Service') }}:** {{ $serviceName }}
@endif
</x-mail::panel>
@endif

@if ($stripeDeepLink)
<x-mail::button :url="$stripeDeepLink">
{{ __('View in Stripe') }}
</x-mail::button>
@endif

{{ __('Thank you') }},<br>
{{ config('app.name') }}
</x-mail::message>
