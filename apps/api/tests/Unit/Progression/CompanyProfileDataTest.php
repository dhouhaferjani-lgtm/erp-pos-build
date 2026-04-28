<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\DTOs\CompanyProfileData;
use App\Modules\Progression\Domain\Enums\GrowthStage;
use PHPUnit\Framework\TestCase;

final class CompanyProfileDataTest extends TestCase
{
    public function test_from_api_response_creates_instance(): void
    {
        $response = [
            'id' => 'uuid-123',
            'tenant_id' => 'tenant-456',
            'vertical' => 'coffee_shop',
            'country' => 'TN',
            'current_stage' => 'stabilize',
            'stage_progress_percent' => 65,
            'total_milestones' => 8,
            'completed_milestones' => 5,
        ];

        $dto = CompanyProfileData::fromApiResponse($response);

        $this->assertSame('uuid-123', $dto->id);
        $this->assertSame('tenant-456', $dto->tenantId);
        $this->assertSame('coffee_shop', $dto->vertical);
        $this->assertSame('TN', $dto->country);
        $this->assertSame(GrowthStage::Stabilize, $dto->currentStage);
        $this->assertSame(65, $dto->stageProgressPercent);
        $this->assertSame(8, $dto->totalMilestones);
        $this->assertSame(5, $dto->completedMilestones);
    }
}
