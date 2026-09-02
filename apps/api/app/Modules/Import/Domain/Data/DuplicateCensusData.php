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
     * @param  array{
     *   counts: array{multi_location_products: int, barcode_identity_conflict_groups: int, barcode_identity_conflict_rows: int},
     *   groups: list<array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}>,
     *   rows: array<int, int>
     * } $barcodeGroups
     */
    public function __construct(
        public array $counts,
        public array $matchedByName,
        public array $refused = [],
        public array $barcodeGroups = [
            'counts' => [
                'multi_location_products' => 0,
                'barcode_identity_conflict_groups' => 0,
                'barcode_identity_conflict_rows' => 0,
            ],
            'groups' => [],
            'rows' => [],
        ],
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

        $barcodeGroups = self::barcodeGroupsFromStorage($payload['barcode_groups'] ?? null);

        return new self($counts, $matched, $refused, $barcodeGroups);
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     matched_by_name: list<int>,
     *     refused: list<array{row_number: int, code: string}>,
     *     barcode_groups: array{
     *       counts: array{multi_location_products: int, barcode_identity_conflict_groups: int, barcode_identity_conflict_rows: int},
     *       groups: list<array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}>,
     *       rows: array<int, int>
     *     }
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
            'barcode_groups' => $this->barcodeGroups,
        ];
    }

    /**
     * @return array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}|null
     */
    public function barcodeGroupForRow(int $rowNumber): ?array
    {
        $index = $this->barcodeGroups['rows'][$rowNumber] ?? null;

        if (! is_int($index)) {
            foreach ($this->barcodeGroups['groups'] as $group) {
                if (in_array($rowNumber, $group['row_numbers'], true)) {
                    return $group;
                }
            }
        }

        return is_int($index) ? ($this->barcodeGroups['groups'][$index] ?? null) : null;
    }

    /**
     * @return array{
     *   counts: array{multi_location_products: int, barcode_identity_conflict_groups: int, barcode_identity_conflict_rows: int},
     *   groups: list<array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}>,
     *   rows: array<int, int>
     * }
     */
    private static function barcodeGroupsFromStorage(mixed $payload): array
    {
        $empty = [
            'counts' => [
                'multi_location_products' => 0,
                'barcode_identity_conflict_groups' => 0,
                'barcode_identity_conflict_rows' => 0,
            ],
            'groups' => [],
            'rows' => [],
        ];
        if (! is_array($payload)) {
            return $empty;
        }

        $rawCounts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        foreach (array_keys($empty['counts']) as $key) {
            $empty['counts'][$key] = is_int($rawCounts[$key] ?? null) ? $rawCounts[$key] : 0;
        }
        $rawGroups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
        foreach ($rawGroups as $group) {
            if (! is_array($group)
                || ! is_string($group['barcode'] ?? null)
                || ! is_string($group['classification'] ?? null)) {
                continue;
            }
            $empty['groups'][] = [
                'barcode' => $group['barcode'],
                'classification' => $group['classification'],
                'row_numbers' => self::intList($group['row_numbers'] ?? null),
                'location_codes' => self::stringList($group['location_codes'] ?? null),
                'differing_fields' => self::stringList($group['differing_fields'] ?? null),
            ];
        }
        $rawRows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        foreach ($rawRows as $rowNumber => $groupIndex) {
            if ((is_int($rowNumber) || ctype_digit((string) $rowNumber)) && is_int($groupIndex)) {
                $empty['rows'][(int) $rowNumber] = $groupIndex;
            }
        }

        return $empty;
    }

    /** @return list<int> */
    private static function intList(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, static fn (mixed $value): bool => is_int($value)))
            : [];
    }

    /** @return list<string> */
    private static function stringList(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value)))
            : [];
    }
}
