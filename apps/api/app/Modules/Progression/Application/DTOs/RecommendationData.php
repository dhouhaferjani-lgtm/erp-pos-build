<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\DTOs;

use App\Modules\Progression\Domain\Enums\RecommendationPriority;
use App\Modules\Progression\Domain\Enums\RecommendationStatus;

final readonly class RecommendationData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public RecommendationPriority $priority,
        public string $actionLabel,
        public string $actionRoute,
        public RecommendationStatus $status,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            id: (string) $response['id'],
            title: (string) $response['title'],
            description: (string) $response['description'],
            priority: RecommendationPriority::from((string) $response['priority']),
            actionLabel: (string) ($response['action_label'] ?? ''),
            actionRoute: (string) ($response['action_route'] ?? ''),
            status: RecommendationStatus::from((string) $response['status']),
        );
    }
}
