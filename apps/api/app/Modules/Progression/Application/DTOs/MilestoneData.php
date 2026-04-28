<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\DTOs;

use App\Modules\Progression\Domain\Enums\MilestoneStatus;

final readonly class MilestoneData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public MilestoneStatus $status,
        public int $progressPercent,
        public string $stage,
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
            status: MilestoneStatus::from((string) $response['status']),
            progressPercent: (int) $response['progress_percent'],
            stage: (string) $response['stage'],
        );
    }
}
