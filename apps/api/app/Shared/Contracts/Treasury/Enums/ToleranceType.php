<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Direction (or absence) of a payment-tolerance discrepancy.
 *
 * Replaces the magic strings ('underpayment' / 'overpayment' / null) previously
 * leaked through PaymentToleranceService::checkTolerance() return arrays.
 *
 * @see PaymentToleranceCheckerContract
 */
#[TypeScript]
enum ToleranceType: string
{
    /**
     * Payment was less than the invoice total (positive shortfall).
     * Maps to legacy `'underpayment'`.
     */
    case Underpayment = 'underpayment';

    /**
     * Payment exceeded the invoice total (negative shortfall / change due).
     * Maps to legacy `'overpayment'`.
     */
    case Overpayment = 'overpayment';

    /**
     * No tolerance gap to evaluate — either the difference is exactly zero,
     * or the tolerance feature is disabled for this country/company.
     * Maps to legacy `null`.
     */
    case None = 'none';
}
