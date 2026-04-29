<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Per-terminal count of training-mode receipts for the NF525 ModeFormation
 * section. The provider returns one row per terminal that produced any
 * training receipts in the export window.
 */
final readonly class Nf525TrainingModeCount
{
    public function __construct(
        public string $terminalId,
        public int $count,
    ) {}
}
