<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * POS payment policy pushed to the device and cached in SQLite for offline
 * use (spec §4.2). Every money-shaped field is a decimal STRING at the
 * company currency scale — a float here re-serializes `0.050` as `0.05`
 * and every signed receipt built from it quarantines (spec §4.2
 * string-fidelity contract).
 */
#[TypeScript]
final class PosPaymentPolicyDTO extends Data
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $currencyCode,
        public readonly int $currencyScale,
        public readonly bool $cashRoundingEnabled,
        /** Canonical zero at currency scale when rounding does not apply. */
        public readonly string $cashRoundingDenomination,
        public readonly bool $tenderToleranceEnabled,
        /** Fraction, NOT a percentage — e.g. "0.0050" for 0.5%. */
        public readonly string $tenderTolerancePercentage,
        /** Absolute ceiling in the COMPANY currency. */
        public readonly string $tenderToleranceMaxAmount,
        public readonly string $refreshedAt,
    ) {}
}
