<x-mail::message>
@if ($context === 'confirmed')
# {{ __('Booking Confirmed') }}

{{ __('A booking has been confirmed at **:business**.', ['business' => $businessName]) }}
@else
# {{ __('New Booking Received') }}

{{ __('A new booking has been received at **:business**.', ['business' => $businessName]) }}
@endif

<x-mail::panel>
**{{ __('Customer') }}:** {{ $customerName }}<br>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Provider') }}:** {{ $providerName }}<br>
**{{ __('Date') }}:** {{ $date }}<br>
**{{ __('Time') }}:** {{ $time }}<br>
**{{ __('Status') }}:** {{ $status }}
</x-mail::panel>

@if ($notes)
**{{ __('Customer notes') }}:** {{ $notes }}
@endif

<x-mail::button :url="$dashboardUrl">
{{ __('View in Dashboard') }}
</x-mail::button>

{{ __('Thank you') }},<br>
{{ config('app.name') }}
</x-mail::message>
