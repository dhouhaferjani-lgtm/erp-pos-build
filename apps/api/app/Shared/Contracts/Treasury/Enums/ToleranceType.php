<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Direction (or absence) of a payment-tolerance discrepancy.
 *
 * @see PaymentToleranceCheckerContract
 */
#[TypeScript]
enum ToleranceType: string
{
    /** Payment was less than the invoice total (positive shortfall). */
    case Underpayment = 'underpayment';

    /** Payment exceeded the invoice total (negative shortfall / change due). */
    case Overpayment = 'overpayment';

    /**
     * No tolerance gap to evaluate — either the difference is exactly zero,
     * or the tolerance feature is disabled for this country/company.
     */
    case None = 'none';
}
