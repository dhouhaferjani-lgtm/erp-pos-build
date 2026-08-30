<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportErrorDetailData
{
    /**
     * @param  list<string>  $accepted
     * @param  list<UnitCandidateData>  $candidates
     * @param  list<string>  $candidateSkus
     */
    public function __construct(
        public ?string $supplied = null,
        public array $accepted = [],
        public array $candidates = [],
        public array $candidateSkus = [],
        public ?string $sku = null,
        public ?string $existingProductId = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): self
    {
        $candidates = [];
        if (is_array($payload['candidates'] ?? null)) {
            foreach ($payload['candidates'] as $candidate) {
                if (is_array($candidate)) {
                    /** @var array<string, mixed> $candidate */
                    $typed = UnitCandidateData::fromStorage($candidate);
                    if ($typed !== null) {
                        $candidates[] = $typed;
                    }
                }
            }
        }

        return new self(
            supplied: self::nullableString($payload, 'supplied'),
            accepted: self::stringList($payload['accepted'] ?? null),
            candidates: $candidates,
            candidateSkus: self::stringList($payload['candidate_skus'] ?? null),
            sku: self::nullableString($payload, 'sku'),
            existingProductId: self::nullableString($payload, 'existing_product_id'),
        );
    }

    /** @return array<string, string|list<string>|list<array{id: string, code: string, name: string, category: string, tier: string}>> */
    public function toStorage(): array
    {
        $detail = [];
        if ($this->supplied !== null) {
            $detail['supplied'] = $this->supplied;
        }
        if ($this->accepted !== []) {
            $detail['accepted'] = $this->accepted;
        }
        if ($this->candidates !== []) {
            $detail['candidates'] = array_map(
                static fn (UnitCandidateData $candidate): array => $candidate->toStorage(),
                $this->candidates,
            );
        }
        if ($this->candidateSkus !== []) {
            $detail['candidate_skus'] = $this->candidateSkus;
        }
        if ($this->sku !== null) {
            $detail['sku'] = $this->sku;
        }
        if ($this->existingProductId !== null) {
            $detail['existing_product_id'] = $this->existingProductId;
        }

        return $detail;
    }

    /** @param array<string, mixed> $payload */
    private static function nullableString(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)))
            : [];
    }
}
