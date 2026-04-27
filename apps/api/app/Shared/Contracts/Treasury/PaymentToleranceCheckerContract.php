<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Shared\Contracts\Treasury\DTOs\ToleranceCheckResult;

/**
 * Cross-module contract for evaluating whether a payment-tolerance gap
 * qualifies for an automatic write-off.
 *
 * Consumers (POS A1, B2B A2 close-with-tolerance, future close paths)
 * depend on this interface, not on the concrete service. Module
 * boundaries are preserved: POS does not import the Treasury service.
 *
 * Boundary semantics — see spec §15:
 *   - $strict = false (A1 / SmartPayment / POS receipt close):
 *     inclusive `<=` against both percentage and absolute thresholds.
 *     A difference exactly at the limit qualifies.
 *   - $strict = true (A2 close-with-tolerance per spec §15):
 *     exclusive `<`. A difference exactly at the limit rejects, closing
 *     a sub-tolerance abuse vector (auditor would otherwise see
 *     "balance == threshold" written off without scrutiny).
 *
 * The contract surface is country+currency-keyed by design — the
 * orchestrator's tolerance configuration model lives at the country level
 * (with system defaults below it). Company-level overrides are exposed
 * separately via PaymentToleranceService::getToleranceSettings() for the
 * UI / threshold-display path; the qualifier surface itself stays
 * country-keyed to keep cross-module callers off the company boundary.
 *
 * @see docs/superpowers/coordination/2026-04-24-payment-tolerance-shift-interface.md §15
 */
interface PaymentToleranceCheckerContract
{
    /**
     * Evaluate whether a tolerance gap qualifies for write-off.
     *
     * @param  string  $shortfall  Absolute difference (scale-4 decimal string, always >= 0).
     *                             Zero is allowed and always returns qualifies=false.
     * @param  string  $invoiceTotal  Invoice total (scale-4 decimal string) — used to compute
     *                                the percentage threshold.
     * @param  string  $currencyCode  ISO 4217 (e.g., 'EUR', 'TND'). Reserved for currency-specific
     *                                threshold resolution; currently informational.
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (e.g., 'FR', 'TN'). Drives threshold
     *                               resolution via CountryPaymentSettings.
     * @param  bool  $strict  false (default) → A1 inclusive `<=`. true → A2 exclusive `<`.
     */
    public function check(
        string $shortfall,
        string $invoiceTotal,
        string $currencyCode,
        string $countryCode,
        bool $strict = false,
    ): ToleranceCheckResult;
}
