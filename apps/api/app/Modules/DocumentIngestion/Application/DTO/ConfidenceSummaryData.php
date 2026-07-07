<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ConfidenceSummaryData extends Data
{
    /**
     * @param  list<string>  $lowConfidenceFields
     */
    public function __construct(
        public ?float $averageConfidence,
        public array $lowConfidenceFields,
        public ReconciliationData $reconciliation,
    ) {}

    /**
     * @return array{average_confidence: float|null, low_confidence_fields: list<string>, reconciliation: array{consistent: bool, flags: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'average_confidence' => $this->averageConfidence,
            'low_confidence_fields' => $this->lowConfidenceFields,
            'reconciliation' => $this->reconciliation->toArray(),
        ];
    }
}
