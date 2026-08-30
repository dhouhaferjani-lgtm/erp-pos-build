<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportPlacementPlanData
{
    /**
     * @param  list<ImportPlacementSegmentData>  $segments
     * @param  list<ImportPlacementSegmentData>  $nodesToCreate
     */
    public function __construct(
        public string $mode,
        public string $locationId,
        public string $locationCode,
        public string $path,
        public ?string $finalNodeId,
        public array $segments,
        public array $nodesToCreate,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromStorage(array $payload): ?self
    {
        $locationId = self::string($payload, 'location_id');
        if ($locationId === '') {
            return null;
        }

        return new self(
            self::string($payload, 'mode'),
            $locationId,
            self::string($payload, 'location_code'),
            self::string($payload, 'path'),
            is_string($payload['final_node_id'] ?? null) ? $payload['final_node_id'] : null,
            self::segments($payload['segments'] ?? null),
            self::segments($payload['nodes_to_create'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toStorage(): array
    {
        return [
            'mode' => $this->mode,
            'location_id' => $this->locationId,
            'location_code' => $this->locationCode,
            'path' => $this->path,
            'final_node_id' => $this->finalNodeId,
            'segments' => array_map(
                static fn (ImportPlacementSegmentData $segment): array => $segment->toStorage(),
                $this->segments,
            ),
            'nodes_to_create' => array_map(
                static fn (ImportPlacementSegmentData $segment): array => $segment->toStorage(),
                $this->nodesToCreate,
            ),
        ];
    }

    /** @return list<ImportPlacementSegmentData> */
    private static function segments(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $segments = [];
        foreach ($payload as $item) {
            if (is_array($item)) {
                $segment = ImportPlacementSegmentData::fromStorage($item);
                if ($segment !== null) {
                    $segments[] = $segment;
                }
            }
        }

        return $segments;
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }
}
