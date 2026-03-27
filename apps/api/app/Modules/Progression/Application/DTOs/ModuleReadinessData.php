<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\DTOs;

use App\Modules\Progression\Domain\Enums\ModuleReadinessStatus;

final readonly class ModuleReadinessData
{
    /**
     * @param  list<string>  $requirements
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $icon,
        public ModuleReadinessStatus $status,
        public int $readinessPercent,
        public string $stage,
        public int $discountPercent,
        public array $requirements,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            id: (string) $response['id'],
            name: (string) $response['name'],
            description: (string) $response['description'],
            icon: (string) $response['icon'],
            status: ModuleReadinessStatus::from((string) $response['status']),
            readinessPercent: (int) $response['readiness_percent'],
            stage: (string) $response['stage'],
            discountPercent: (int) ($response['discount_percent'] ?? 0),
            requirements: array_values(array_map('strval', (array) ($response['requirements'] ?? []))),
        );
    }
}
