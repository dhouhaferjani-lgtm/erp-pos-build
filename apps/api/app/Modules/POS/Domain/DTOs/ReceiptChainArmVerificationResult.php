<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

/**
 * One independently attributable receipt-chain verification arm.
 */
final readonly class ReceiptChainArmVerificationResult
{
    public function __construct(
        public bool $isValid,
        public int $count,
        public ?string $breakPoint = null,
    ) {}
}
