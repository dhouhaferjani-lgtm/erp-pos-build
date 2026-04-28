<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Result of verifying one terminal's hash chain (receipts or Z-reports).
 *
 * Compliance presents this to the operator via /verify-chains; the policy
 * "walk the chain and rehash each row" stays inside POS where the hash
 * services live, so Compliance gains zero new POS-Domain knowledge.
 *
 * `$failedAtSequence` is the chain_sequence (receipts) or z_number
 * (Z-reports) at which verification stopped; null on success.
 */
final readonly class Nf525ChainVerificationResult
{
    public function __construct(
        public bool $isValid,
        public int $totalRows,
        public int $verifiedRows,
        public ?int $failedAtSequence,
        public ?string $error,
    ) {}
}
