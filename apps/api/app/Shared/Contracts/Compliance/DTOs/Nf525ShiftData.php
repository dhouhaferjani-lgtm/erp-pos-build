<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a shift (technical event: shift open / close) for NF525 export.
 *
 * Immutable view of POS\Domain\Shift. The XML builder emits one or two
 * `<Evenement>` rows per shift depending on whether `$closedAtIso8601` is set.
 */
final readonly class Nf525ShiftData
{
    public function __construct(
        public string $id,
        public string $terminalId,
        public string $cashierId,
        public int $shiftNumber,
        public string $openingCash,
        public string $openedAtIso8601,
        public ?string $closedAtIso8601,
        public ?string $expectedCash,
        public ?string $actualCash,
        public ?string $variance,
    ) {}
}
