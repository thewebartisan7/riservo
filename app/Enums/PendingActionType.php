<?php

namespace App\Enums;

enum PendingActionType: string
{
    case RiservoEventDeletedInGoogle = 'riservo_event_deleted_in_google';
    case ExternalBookingConflict = 'external_booking_conflict';
}
