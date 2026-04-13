<x-mail::message>
# {{ __('Appointment Reminder') }}

{{ __('This is a reminder about your upcoming appointment at **:business**.', ['business' => $businessName]) }}

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Collaborator') }}:** {{ $collaboratorName }}<br>
**{{ __('Date') }}:** {{ $date }}<br>
**{{ __('Time') }}:** {{ $time }}
</x-mail::panel>

<x-mail::button :url="$viewUrl">
{{ __('View Booking') }}
</x-mail::button>

{{ __('Thank you') }},<br>
{{ $businessName }}
</x-mail::message>
