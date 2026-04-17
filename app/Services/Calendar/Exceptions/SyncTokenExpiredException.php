<?php

namespace App\Services\Calendar\Exceptions;

use RuntimeException;

/**
 * Google returned 410 Gone on events.list — the stored sync token is too old.
 * The pull job clears the token and performs a forward-only re-sync.
 */
class SyncTokenExpiredException extends RuntimeException {}
