<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

/**
 * Result of recording a transfer via the write port's `transfer()` method (Task 12).
 */
final readonly class TransferResult
{
    /**
     * @param  MovementResult  $outLeg  Result of the out-leg movement on the source repository
     * @param  MovementResult  $inLeg  Result of the in-leg movement on the destination repository
     */
    public function __construct(
        public MovementResult $outLeg,
        public MovementResult $inLeg,
    ) {}
}
