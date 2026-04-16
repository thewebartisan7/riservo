<x-mail::message>
# {{ __('Booking Cancelled') }}

@if ($cancelledBy === 'customer')
{{ __('A booking at **:business** has been cancelled by the customer.', ['business' => $businessName]) }}
@else
{{ __('Your booking at **:business** has been cancelled.', ['business' => $businessName]) }}
@endif

<x-mail::panel>
@if ($cancelledBy === 'customer')
**{{ __('Customer') }}:** {{ $customerName }}<br>
@endif
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Provider') }}:** {{ $providerName }}<br>
**{{ __('Date') }}:** {{ $date }}<br>
**{{ __('Time') }}:** {{ $time }}
</x-mail::panel>

@if ($cancelledBy === 'business')
{{ __('If you have any questions, please contact :business directly.', ['business' => $businessName]) }}
@endif

{{ __('Thank you') }},<br>
@if ($cancelledBy === 'customer')
{{ config('app.name') }}
@else
{{ $businessName }}
@endif
</x-mail::message>
