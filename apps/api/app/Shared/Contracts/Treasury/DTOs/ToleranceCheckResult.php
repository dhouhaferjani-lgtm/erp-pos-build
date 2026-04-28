<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

use App\Shared\Contracts\Treasury\Enums\ToleranceType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed result of a payment-tolerance qualifier evaluation.
 *
 * All decimal strings are emitted at scale 4 (matches Payment Tolerance
 * contract v1.1's "scale 4 internally" rule).
 *
 * @see App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract
 */
#[TypeScript]
final class ToleranceCheckResult extends Data
{
    public function __construct(
        /** Whether the difference is admissible as a tolerance write-off. */
        public bool $qualifies,
        /**
         * Absolute difference between payment and invoice total, scale 4.
         * Always non-negative. Zero when there is no gap (or feature disabled).
         */
        public string $difference,
        /** Direction of the gap (or None). See enum docblock. */
        public ToleranceType $type,
        /**
         * Human-readable explanation. Populated when `qualifies = false`
         * (e.g., "Exceeds percentage threshold (0.0050)") and on the
         * "Tolerance disabled" branch. Null on the qualifying path.
         */
        public ?string $reason,
    ) {}
}
