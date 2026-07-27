<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\MovementDirection;

final readonly class ParsedStatementLine
{
    /** @param numeric-string $amount */
    public function __construct(
        public int $lineNumber,
        public string $valueDate,
        public ?string $bookingDate,
        public MovementDirection $direction,
        public string $amount,
        public ?string $reference,
        public ?string $bankTransactionId,
        public string $label,
        public ?string $counterpartyHint,
        public int $occurrenceIndex,
        public string $fingerprint,
    ) {}
}
