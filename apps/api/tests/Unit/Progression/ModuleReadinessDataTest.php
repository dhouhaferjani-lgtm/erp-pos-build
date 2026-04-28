<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\DTOs\ModuleReadinessData;
use App\Modules\Progression\Domain\Enums\ModuleReadinessStatus;
use PHPUnit\Framework\TestCase;

final class ModuleReadinessDataTest extends TestCase
{
    public function test_from_api_response_creates_instance(): void
    {
        $response = [
            'id' => 'mod-inv',
            'name' => 'Inventory',
            'description' => 'Track stock levels, receive goods, manage suppliers',
            'icon' => 'package',
            'status' => 'ready',
            'readiness_percent' => 92,
            'stage' => 'stabilize',
            'discount_percent' => 15,
            'requirements' => ['Track cost of goods', 'Consistent daily closing'],
        ];

        $dto = ModuleReadinessData::fromApiResponse($response);

        $this->assertSame('mod-inv', $dto->id);
        $this->assertSame('Inventory', $dto->name);
        $this->assertSame('Track stock levels, receive goods, manage suppliers', $dto->description);
        $this->assertSame('package', $dto->icon);
        $this->assertSame(ModuleReadinessStatus::Ready, $dto->status);
        $this->assertSame(92, $dto->readinessPercent);
        $this->assertSame('stabilize', $dto->stage);
        $this->assertSame(15, $dto->discountPercent);
        $this->assertCount(2, $dto->requirements);
    }
}
