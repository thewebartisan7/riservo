/**
 * Format a money value (in minor units / cents) for display.
 *
 * D-184 / G-003 partial (PAYMENTS Hardening Round 2): replaces the prior
 * `${currency.toUpperCase()} ${(cents / 100).toFixed(2)}` pattern. Uses
 * `Intl.NumberFormat` with explicit currency so non-CHF currencies render
 * with the locale's currency rules and CHF renders with the apostrophe-
 * thousands convention the user's browser locale provides.
 *
 * Note: this helper is for DISPLAY only. The locale-aware parser for
 * input (CH operators expect to type `10,50` or `1'000.50` and have it
 * accepted) is a separate UX session — see BACKLOG.
 */
export function formatMoney(cents: number, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(cents / 100);
}
