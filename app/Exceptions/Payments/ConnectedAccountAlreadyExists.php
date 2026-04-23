<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Thrown by `ConnectedAccountController::create()` when a parallel request
 * has already created the active row inside the transaction's lock window
 * (D-122 / Codex Round 1). The outer catch translates it to the same
 * "already connected" flash message as the pre-transaction existence
 * check, so concurrent Enable clicks resolve identically.
 */
class ConnectedAccountAlreadyExists extends RuntimeException {}
