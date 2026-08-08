<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;

/**
 * Result of posting one repository adjustment (document + journal entry +
 * movement) via {@see RepositoryAdjustmentServiceInterface::post()}.
 */
final readonly class RepositoryAdjustmentResult
{
    /**
     * @param  string  $adjustmentId  UUID of the justifying `repository_adjustments` document
     * @param  string  $journalEntryId  UUID of the POSTED 658/758 journal entry
     * @param  string  $movementId  UUID of the written (or idempotently matched) repository movement
     * @param  string  $balanceAfter  numeric-string repository balance immediately after the movement
     * @param  int  $ordinal  Monotonic per-repository sequence number of the movement
     * @param  bool  $wasIdempotentHit  True when the movement already existed (replay) and nothing new was written
     * @param  string  $normalizedAmount  The single, currency-scale-normalized amount every artifact carries
     */
    public function __construct(
        public string $adjustmentId,
        public string $journalEntryId,
        public string $movementId,
        public string $balanceAfter,
        public int $ordinal,
        public bool $wasIdempotentHit,
        public string $normalizedAmount,
    ) {}
}
