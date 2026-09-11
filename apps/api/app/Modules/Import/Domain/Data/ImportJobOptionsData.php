<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\DuplicatePolicy;

final readonly class ImportJobOptionsData
{
    /**
     * @param  list<string>|null  $placementNodeTypes
     * @param  list<string>|null  $sourceHeaders
     */
    public function __construct(
        public ?DuplicateCensusData $duplicateCensus,
        public ?DuplicatePolicy $duplicatePolicy,
        public ?string $locationCode,
        public ?string $priceAuthority,
        public ?string $placementMode,
        public ?array $placementNodeTypes,
        public ?bool $enrichmentEnabled,
        public ?bool $multiLocationConfirmed,
        public ?array $sourceHeaders = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $rawCensus = $payload['duplicate_census'] ?? null;
        $rawNodeTypes = $payload['placement_node_types'] ?? null;
        $sourceHeaders = $payload['source_headers'] ?? null;

        return new self(
            is_array($rawCensus) ? DuplicateCensusData::fromStorage($rawCensus) : null,
            is_string($payload['duplicate_policy'] ?? null)
                ? DuplicatePolicy::tryFrom($payload['duplicate_policy'])
                : null,
            self::string($payload, 'location_code'),
            self::string($payload, 'price_authority'),
            self::string($payload, 'placement_mode'),
            is_array($rawNodeTypes)
                ? array_values(array_filter($rawNodeTypes, static fn (mixed $value): bool => is_string($value)))
                : null,
            is_bool($payload['enrichment_enabled'] ?? null) ? $payload['enrichment_enabled'] : null,
            is_bool($payload['multi_location_confirmed'] ?? null) ? $payload['multi_location_confirmed'] : null,
            is_array($sourceHeaders) ? array_values(array_filter($sourceHeaders, is_string(...))) : null,
        );
    }

    /**
     * @return array{
     *   duplicate_census?: array{
     *     counts: array<string, int>,
     *     matched_by_name: list<int>,
     *     refused: list<array{row_number: int, code: string}>,
     *     barcode_groups: array{
     *       counts: array{multi_location_products: int, barcode_identity_conflict_groups: int, barcode_identity_conflict_rows: int},
     *       groups: list<array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}>,
     *       rows: array<int, int>
     *     }
     *   },
     *   duplicate_policy?: string,
     *   location_code?: string,
     *   price_authority?: string,
     *   placement_mode?: string,
     *   placement_node_types?: list<string>,
     *   enrichment_enabled?: bool,
     *   source_headers?: list<string>,
     *   multi_location_confirmed?: bool
     * }
     */
    public function toStorage(): array
    {
        $options = [];
        if ($this->duplicateCensus !== null) {
            $options['duplicate_census'] = $this->duplicateCensus->toStorage();
        }
        if ($this->duplicatePolicy !== null) {
            $options['duplicate_policy'] = $this->duplicatePolicy->value;
        }
        if ($this->locationCode !== null) {
            $options['location_code'] = $this->locationCode;
        }
        if ($this->priceAuthority !== null) {
            $options['price_authority'] = $this->priceAuthority;
        }
        if ($this->placementMode !== null) {
            $options['placement_mode'] = $this->placementMode;
        }
        if ($this->placementNodeTypes !== null) {
            $options['placement_node_types'] = $this->placementNodeTypes;
        }
        if ($this->enrichmentEnabled !== null) {
            $options['enrichment_enabled'] = $this->enrichmentEnabled;
        }
        if ($this->multiLocationConfirmed !== null) {
            $options['multi_location_confirmed'] = $this->multiLocationConfirmed;
        }

        if ($this->sourceHeaders !== null) {
            $options['source_headers'] = $this->sourceHeaders;
        }

        return $options;
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }
}
