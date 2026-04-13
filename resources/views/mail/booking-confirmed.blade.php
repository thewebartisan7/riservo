<x-mail::message>
# {{ __('Booking Confirmed') }}

{{ __('Your booking at **:business** has been confirmed.', ['business' => $businessName]) }}

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Collaborator') }}:** {{ $collaboratorName }}<br>
**{{ __('Date') }}:** {{ $date }}<br>
**{{ __('Time') }}:** {{ $time }}
</x-mail::panel>

<x-mail::button :url="$viewUrl">
{{ __('View Booking') }}
</x-mail::button>

{{ __('If you need to cancel or make changes, click the button above to manage your booking.') }}

{{ __('Thank you') }},<br>
{{ $businessName }}
</x-mail::message>
