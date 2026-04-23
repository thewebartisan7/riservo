<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * Thrown by `ConnectedAccountController::create()` when Stripe replays an
 * `acct_…` that already belongs to a different Business in our DB (D-134 /
 * Codex Round 6). The replay-recovery branch must NOT silently re-parent
 * another tenant's connected-account row — that would corrupt ownership
 * and destroy the audit / late-webhook refund linkage tied to the
 * original Business. The outer catch translates this into a manual-
 * reconciliation flash to the admin.
 */
class ConnectedAccountReplayCrossTenant extends RuntimeException {}
