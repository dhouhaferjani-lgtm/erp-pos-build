<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\DTOs\MilestoneData;
use App\Modules\Progression\Domain\Enums\MilestoneStatus;
use PHPUnit\Framework\TestCase;

final class MilestoneDataTest extends TestCase
{
    public function test_from_api_response_creates_instance(): void
    {
        $response = [
            'id' => 'ms-1',
            'name' => 'First 100 sales',
            'description' => 'Record your first 100 sales transactions',
            'status' => 'completed',
            'progress_percent' => 100,
            'stage' => 'launch',
        ];

        $dto = MilestoneData::fromApiResponse($response);

        $this->assertSame('ms-1', $dto->id);
        $this->assertSame('First 100 sales', $dto->name);
        $this->assertSame('Record your first 100 sales transactions', $dto->description);
        $this->assertSame(MilestoneStatus::Completed, $dto->status);
        $this->assertSame(100, $dto->progressPercent);
        $this->assertSame('launch', $dto->stage);
    }
}
