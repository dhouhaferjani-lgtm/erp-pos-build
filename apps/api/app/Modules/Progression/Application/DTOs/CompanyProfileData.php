<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\DTOs;

use App\Modules\Progression\Domain\Enums\GrowthStage;

final readonly class CompanyProfileData
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $vertical,
        public string $country,
        public GrowthStage $currentStage,
        public int $stageProgressPercent,
        public int $totalMilestones,
        public int $completedMilestones,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            id: (string) $response['id'],
            tenantId: (string) $response['tenant_id'],
            vertical: (string) $response['vertical'],
            country: (string) $response['country'],
            currentStage: GrowthStage::from((string) $response['current_stage']),
            stageProgressPercent: (int) $response['stage_progress_percent'],
            totalMilestones: (int) $response['total_milestones'],
            completedMilestones: (int) $response['completed_milestones'],
        );
    }
}
