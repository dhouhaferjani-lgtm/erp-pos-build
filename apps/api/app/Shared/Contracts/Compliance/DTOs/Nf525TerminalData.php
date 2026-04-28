<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a terminal for NF525 hash-chain export.
 *
 * Immutable view of POS\Domain\Terminal — limited to the fields the JET XML
 * needs (the chain summary section).
 */
final readonly class Nf525TerminalData
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $genesisSeed,
        public int $currentSequence,
        public int $currentYear,
        public ?string $lastHash,
    ) {}
}
