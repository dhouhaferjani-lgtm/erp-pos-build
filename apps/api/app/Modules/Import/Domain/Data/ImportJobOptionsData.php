<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\DuplicatePolicy;

final readonly class ImportJobOptionsData
{
    /** @param list<string>|null $placementNodeTypes */
    public function __construct(
        public ?DuplicateCensusData $duplicateCensus,
        public ?DuplicatePolicy $duplicatePolicy,
        public ?string $locationCode,
        public ?string $priceAuthority,
        public ?string $placementMode,
        public ?array $placementNodeTypes,
        public ?bool $enrichmentEnabled,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $rawCensus = $payload['duplicate_census'] ?? null;
        $rawNodeTypes = $payload['placement_node_types'] ?? null;

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
        );
    }

    /**
     * @return array<string, bool|string|list<string>|array{
     *     counts: array<string, int>,
     *     matched_by_name: list<int>,
     *     refused: list<array{row_number: int, code: string}>
     * }>
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

        return $options;
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }
}
