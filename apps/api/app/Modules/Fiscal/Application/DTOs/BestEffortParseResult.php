<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

final readonly class BestEffortParseResult
{
    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<ParseDefect>  $defects
     */
    public function __construct(
        public array $parsed,
        public array $defects,
    ) {}

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function defectsToArray(): array
    {
        return array_map(
            static fn (ParseDefect $defect): array => $defect->toArray(),
            $this->defects,
        );
    }
}
