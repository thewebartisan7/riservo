<?php

namespace App\Services\Calendar\Exceptions;

use RuntimeException;

class UnsupportedCalendarProviderException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        return new self("No CalendarProvider implementation is registered for [{$provider}].");
    }
}
