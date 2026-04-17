<x-mail::message>
# {{ __('Booking Rescheduled') }}

{{ __('Your booking at **:business** has been moved to a new time.', ['business' => $businessName]) }}

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Provider') }}:** {{ $providerName }}<br>
**{{ __('Previously') }}:** {{ $previousDate }} · {{ $previousTime }}<br>
**{{ __('Now') }}:** {{ $newDate }} · {{ $newTime }}
</x-mail::panel>

<x-mail::button :url="$viewUrl">
{{ __('View Booking') }}
</x-mail::button>

{{ __('If the new time does not work for you, click the button above to manage or cancel your booking.') }}

{{ __('Thank you') }},<br>
{{ $businessName }}
</x-mail::message>
