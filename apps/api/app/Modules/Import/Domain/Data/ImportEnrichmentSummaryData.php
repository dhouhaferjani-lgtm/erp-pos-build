<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\ImportWarningCode;

final readonly class ImportEnrichmentSummaryData
{
    /** @param array<string, int> $counts */
    public function __construct(public array $counts) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): self
    {
        $allowed = ['enriched', ...array_map(
            static fn (ImportWarningCode $code): string => $code->value,
            [
                ImportWarningCode::EnrichmentNotFound,
                ImportWarningCode::EnrichmentUnavailable,
                ImportWarningCode::EnrichmentInvalidBarcode,
                ImportWarningCode::EnrichmentCapExceeded,
                ImportWarningCode::EnrichmentVerticalNotSupported,
                ImportWarningCode::EnrichmentBarcodeMissing,
            ],
        )];
        $counts = [];

        foreach ($allowed as $key) {
            $value = $payload[$key] ?? 0;
            if (is_int($value) && $value > 0) {
                $counts[$key] = $value;
            }
        }

        return new self($counts);
    }

    /** @return array<string, int> */
    public function toStorage(): array
    {
        return $this->counts;
    }
}
