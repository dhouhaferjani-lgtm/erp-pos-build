<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a grand-total event for NF525 export.
 *
 * Immutable view of POS\Domain\GrandtotalEvent. Period and perpetual totals
 * are JSON pass-through arrays so refund-flow can extend them without DTO
 * shape changes.
 */
final readonly class Nf525GrandTotalData
{
    /**
     * @param  array<string, mixed>  $periodTotals
     * @param  array<string, mixed>  $perpetualTotals
     */
    public function __construct(
        public string $id,
        public string $terminalId,
        public string $eventType,
        public int $sequenceNumber,
        public string $fiscalHash,
        public ?string $previousHash,
        public string $periodStartIso8601,
        public string $periodEndIso8601,
        public string $generatedAtIso8601,
        public array $periodTotals,
        public array $perpetualTotals,
    ) {}
}
