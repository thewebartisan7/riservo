<x-mail::message>
@if ($context === 'confirmed')
# {{ __('Booking Confirmed') }}

{{ __('A booking has been confirmed at **:business**.', ['business' => $businessName]) }}
@elseif ($context === 'paid_awaiting_confirmation')
# {{ __('Payment received') }}

{{ __('We received your payment. **:business** will confirm your booking shortly. If they cannot accept it, you will receive an automatic full refund.', ['business' => $businessName]) }}
@elseif ($context === 'pending_unpaid_awaiting_confirmation')
# {{ __('Booking request received') }}

{{ __('Your booking request at **:business** has been received and is pending their confirmation. Your online payment did not complete — if the business accepts your booking, you can pay at the appointment.', ['business' => $businessName]) }}
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
