<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Application\DTOs\RecommendationData;
use App\Modules\Progression\Domain\Enums\RecommendationPriority;
use App\Modules\Progression\Domain\Enums\RecommendationStatus;
use PHPUnit\Framework\TestCase;

final class RecommendationDataTest extends TestCase
{
    public function test_from_api_response_creates_instance(): void
    {
        $response = [
            'id' => 'rec-1',
            'title' => 'Start tracking purchase costs',
            'description' => 'You are selling well but margins are invisible.',
            'priority' => 'high',
            'action_label' => 'Show me how',
            'action_route' => '/products',
            'status' => 'pending',
        ];

        $dto = RecommendationData::fromApiResponse($response);

        $this->assertSame('rec-1', $dto->id);
        $this->assertSame('Start tracking purchase costs', $dto->title);
        $this->assertSame('You are selling well but margins are invisible.', $dto->description);
        $this->assertSame(RecommendationPriority::High, $dto->priority);
        $this->assertSame('Show me how', $dto->actionLabel);
        $this->assertSame('/products', $dto->actionRoute);
        $this->assertSame(RecommendationStatus::Pending, $dto->status);
    }
}
