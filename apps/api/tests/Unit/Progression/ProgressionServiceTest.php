<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Application\DTOs\CompanyProfileData;
use App\Modules\Progression\Application\DTOs\MilestoneData;
use App\Modules\Progression\Application\DTOs\ModuleReadinessData;
use App\Modules\Progression\Application\Services\ProgressionService;
use App\Modules\Progression\Domain\Enums\GrowthStage;
use App\Modules\Progression\Domain\Enums\MilestoneStatus;
use App\Modules\Progression\Domain\Enums\ModuleReadinessStatus;
use App\Modules\Progression\Domain\Enums\RecommendationPriority;
use PHPUnit\Framework\TestCase;

final class ProgressionServiceTest extends TestCase
{
    private ProgressionService $service;

    private GrowthAdvisorClientInterface $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockClient = $this->createMock(GrowthAdvisorClientInterface::class);
        $this->service = new ProgressionService($this->mockClient);
    }

    public function test_get_profile_returns_dto_on_success(): void
    {
        $this->mockClient->method('getCompanyProfile')
            ->with('comp-1')
            ->willReturn([
                'id' => 'comp-1',
                'tenant_id' => 'tenant-1',
                'vertical' => 'coffee_shop',
                'country' => 'TN',
                'current_stage' => 'stabilize',
                'stage_progress_percent' => 65,
                'total_milestones' => 8,
                'completed_milestones' => 5,
            ]);

        $result = $this->service->getProfile('comp-1');

        $this->assertInstanceOf(CompanyProfileData::class, $result);
        $this->assertSame(GrowthStage::Stabilize, $result->currentStage);
        $this->assertSame(65, $result->stageProgressPercent);
    }

    public function test_get_profile_returns_null_when_service_unavailable(): void
    {
        $this->mockClient->method('getCompanyProfile')
            ->with('comp-1')
            ->willReturn(null);

        $result = $this->service->getProfile('comp-1');

        $this->assertNull($result);
    }

    public function test_get_milestones_returns_dto_array(): void
    {
        $this->mockClient->method('getMilestones')
            ->with('comp-1')
            ->willReturn([
                ['id' => 'ms-1', 'name' => 'First sale', 'description' => 'Make a sale', 'status' => 'completed', 'progress_percent' => 100, 'stage' => 'launch'],
                ['id' => 'ms-2', 'name' => 'Add products', 'description' => 'Add 50 products', 'status' => 'in_progress', 'progress_percent' => 68, 'stage' => 'stabilize'],
            ]);

        $result = $this->service->getMilestones('comp-1');

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MilestoneData::class, $result[0]);
        $this->assertSame(MilestoneStatus::Completed, $result[0]->status);
        $this->assertSame(MilestoneStatus::InProgress, $result[1]->status);
    }

    public function test_get_milestones_returns_empty_array_on_failure(): void
    {
        $this->mockClient->method('getMilestones')
            ->with('comp-1')
            ->willReturn([]);

        $result = $this->service->getMilestones('comp-1');

        $this->assertSame([], $result);
    }

    public function test_get_modules_returns_dto_array(): void
    {
        $this->mockClient->method('getModules')
            ->with('comp-1')
            ->willReturn([
                ['id' => 'mod-inv', 'name' => 'Inventory', 'description' => 'Track stock', 'icon' => 'package', 'status' => 'ready', 'readiness_percent' => 92, 'stage' => 'stabilize', 'discount_percent' => 15, 'requirements' => []],
            ]);

        $result = $this->service->getModules('comp-1');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ModuleReadinessData::class, $result[0]);
        $this->assertSame(ModuleReadinessStatus::Ready, $result[0]->status);
    }

    public function test_get_recommendations_returns_dto_array_sorted_by_priority(): void
    {
        $this->mockClient->method('getRecommendations')
            ->with('comp-1')
            ->willReturn([
                ['id' => 'rec-1', 'title' => 'Low', 'description' => 'D', 'priority' => 'low', 'action_label' => '', 'action_route' => '', 'status' => 'pending'],
                ['id' => 'rec-2', 'title' => 'High', 'description' => 'D', 'priority' => 'high', 'action_label' => '', 'action_route' => '', 'status' => 'pending'],
                ['id' => 'rec-3', 'title' => 'Medium', 'description' => 'D', 'priority' => 'medium', 'action_label' => '', 'action_route' => '', 'status' => 'pending'],
            ]);

        $result = $this->service->getRecommendations('comp-1');

        $this->assertCount(3, $result);
        $this->assertSame(RecommendationPriority::High, $result[0]->priority);
        $this->assertSame(RecommendationPriority::Medium, $result[1]->priority);
        $this->assertSame(RecommendationPriority::Low, $result[2]->priority);
    }

    public function test_activate_module_returns_dto_on_success(): void
    {
        $this->mockClient->method('activateModule')
            ->with('comp-1', 'mod-inv')
            ->willReturn(['id' => 'mod-inv', 'name' => 'Inventory', 'description' => 'Track stock', 'icon' => 'package', 'status' => 'active', 'readiness_percent' => 100, 'stage' => 'stabilize', 'discount_percent' => 15, 'requirements' => []]);

        $result = $this->service->activateModule('comp-1', 'mod-inv');

        $this->assertInstanceOf(ModuleReadinessData::class, $result);
        $this->assertSame(ModuleReadinessStatus::Active, $result->status);
    }

    public function test_activate_module_returns_null_when_service_unavailable(): void
    {
        $this->mockClient->method('activateModule')
            ->with('comp-1', 'mod-inv')
            ->willReturn(null);

        $result = $this->service->activateModule('comp-1', 'mod-inv');

        $this->assertNull($result);
    }

    public function test_is_available_delegates_to_client(): void
    {
        $this->mockClient->method('isCircuitOpen')->willReturn(false);
        $this->assertTrue($this->service->isAvailable());

        $mockClient2 = $this->createMock(GrowthAdvisorClientInterface::class);
        $mockClient2->method('isCircuitOpen')->willReturn(true);
        $service2 = new ProgressionService($mockClient2);
        $this->assertFalse($service2->isAvailable());
    }
}
