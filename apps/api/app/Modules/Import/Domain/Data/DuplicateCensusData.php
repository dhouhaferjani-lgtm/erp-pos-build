<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;

final readonly class DuplicateCensusData
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<int>  $matchedByName
     * @param  list<array{row_number: int, code: ImportErrorCode}>  $refused
     */
    public function __construct(
        public array $counts,
        public array $matchedByName,
        public array $refused = [],
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

        $refused = [];
        $rawRefused = $payload['refused'] ?? [];
        if (is_array($rawRefused)) {
            foreach ($rawRefused as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                $rowNumber = $detail['row_number'] ?? null;
                $code = is_string($detail['code'] ?? null)
                    ? ImportErrorCode::tryFrom($detail['code'])
                    : null;
                if (is_int($rowNumber) && $code !== null) {
                    $refused[] = ['row_number' => $rowNumber, 'code' => $code];
                }
            }
        }

        return new self($counts, $matched, $refused);
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     matched_by_name: list<int>,
     *     refused: list<array{row_number: int, code: string}>
     * }
     */
    public function toStorage(): array
    {
        return [
            'counts' => $this->counts,
            'matched_by_name' => $this->matchedByName,
            'refused' => array_map(
                static fn (array $detail): array => [
                    'row_number' => $detail['row_number'],
                    'code' => $detail['code']->value,
                ],
                $this->refused,
            ),
        ];
    }
}
