<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class UnitCandidateData
{
    /** @param 'system'|'tenant'|'company' $tier */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $category,
        public string $tier,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): ?self
    {
        $tier = is_string($payload['tier'] ?? null) ? $payload['tier'] : '';
        if (! in_array($tier, ['system', 'tenant', 'company'], true)) {
            return null;
        }

        return new self(
            self::string($payload, 'id'),
            self::string($payload, 'code'),
            self::string($payload, 'name'),
            self::string($payload, 'category'),
            $tier,
        );
    }

    /** @return array{id: string, code: string, name: string, category: string, tier: string} */
    public function toStorage(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'tier' => $this->tier,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }
}
