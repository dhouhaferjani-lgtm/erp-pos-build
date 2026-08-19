<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

/**
 * One independently attributable receipt-chain verification arm.
 *
 * `$count` is the receipt coverage shown to the operator. `$inspectedCount`
 * is the underlying source rows actually verified and differs only for the
 * authoritative fiscal arm, which must walk non-receipt events too.
 */
final readonly class ReceiptChainArmVerificationResult
{
    public int $inspectedCount;

    public function __construct(
        public bool $isValid,
        /** Number of receipt rows represented by this arm in operator output. */
        public int $count,
        public ?string $breakPoint = null,
        ?int $inspectedCount = null,
    ) {
        $this->inspectedCount = $inspectedCount ?? $count;
    }
}
