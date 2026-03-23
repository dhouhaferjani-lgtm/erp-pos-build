<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

readonly class VatSummary
{
    /**
     * @param  VatAggregation[]  $outputBreakdowns
     * @param  VatAggregation[]  $inputBreakdowns
     */
    public function __construct(
        public array $outputBreakdowns,
        public array $inputBreakdowns,
        public string $totalOutputVat,
        public string $totalInputVat,
    ) {}

    /** @return array<string, string|array<int, array<string, string|int|bool|null>>> */
    public function toArray(): array
    {
        return [
            'output_breakdowns' => array_map(
                static fn (VatAggregation $a): array => $a->toArray(),
                $this->outputBreakdowns
            ),
            'input_breakdowns' => array_map(
                static fn (VatAggregation $a): array => $a->toArray(),
                $this->inputBreakdowns
            ),
            'total_output_vat' => $this->totalOutputVat,
            'total_input_vat' => $this->totalInputVat,
        ];
    }
}
