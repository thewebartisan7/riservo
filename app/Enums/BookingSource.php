<?php

namespace App\Enums;

enum BookingSource: string
{
    case Riservo = 'riservo';
    case GoogleCalendar = 'google_calendar';
    case Manual = 'manual';
}
