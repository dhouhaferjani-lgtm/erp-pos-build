<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class ParsedStatement
{
    /**
     * @param  list<ParsedStatementLine>  $lines
     * @param  list<array{row: int, reason: string}>  $unparseableRows
     * @param  numeric-string|null  $detectedOpening
     * @param  numeric-string|null  $detectedClosing
     */
    public function __construct(
        public array $lines,
        public int $droppedZeroAmountRows,
        public array $unparseableRows,
        public ?string $detectedOpening,
        public ?string $detectedClosing,
    ) {}
}
