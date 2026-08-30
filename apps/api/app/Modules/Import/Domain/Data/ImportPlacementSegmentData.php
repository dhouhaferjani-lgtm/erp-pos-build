<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportPlacementSegmentData
{
    public function __construct(
        public int $depth,
        public ?string $existingNodeId,
        public string $nodeType,
        public string $name,
        public string $code,
        public string $path,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): ?self
    {
        if (! is_int($payload['depth'] ?? null)) {
            return null;
        }

        return new self(
            $payload['depth'],
            is_string($payload['existing_node_id'] ?? null) ? $payload['existing_node_id'] : null,
            self::string($payload, 'node_type'),
            self::string($payload, 'name'),
            self::string($payload, 'code'),
            self::string($payload, 'path'),
        );
    }

    /** @return array{depth: int, existing_node_id: string|null, node_type: string, name: string, code: string, path: string} */
    public function toStorage(): array
    {
        return [
            'depth' => $this->depth,
            'existing_node_id' => $this->existingNodeId,
            'node_type' => $this->nodeType,
            'name' => $this->name,
            'code' => $this->code,
            'path' => $this->path,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }
}
