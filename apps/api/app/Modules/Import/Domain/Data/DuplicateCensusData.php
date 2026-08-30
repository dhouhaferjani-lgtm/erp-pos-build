<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\DuplicateBucket;

final readonly class DuplicateCensusData
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<int>  $matchedByName
     */
    public function __construct(
        public array $counts,
        public array $matchedByName,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $counts = [];
        $rawCounts = $payload['counts'] ?? [];
        if (is_array($rawCounts)) {
            foreach (DuplicateBucket::cases() as $bucket) {
                $value = $rawCounts[$bucket->value] ?? 0;
                $counts[$bucket->value] = is_int($value) ? $value : 0;
            }
        }

        $matched = [];
        $rawMatched = $payload['matched_by_name'] ?? [];
        if (is_array($rawMatched)) {
            foreach ($rawMatched as $rowNumber) {
                if (is_int($rowNumber)) {
                    $matched[] = $rowNumber;
                }
            }
        }

        return new self($counts, $matched);
    }

    /** @return array{counts: array<string, int>, matched_by_name: list<int>} */
    public function toStorage(): array
    {
        return ['counts' => $this->counts, 'matched_by_name' => $this->matchedByName];
    }
}
