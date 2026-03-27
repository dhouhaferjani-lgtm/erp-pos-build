<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Domain\Enums\RecommendationPriority;
use PHPUnit\Framework\TestCase;

final class RecommendationPriorityTest extends TestCase
{
    public function test_has_exactly_three_cases(): void
    {
        $this->assertCount(3, RecommendationPriority::cases());
    }

    public function test_cases_are_high_medium_low(): void
    {
        $this->assertSame('high', RecommendationPriority::High->value);
        $this->assertSame('medium', RecommendationPriority::Medium->value);
        $this->assertSame('low', RecommendationPriority::Low->value);
    }

    public function test_sort_order_returns_numeric_weight(): void
    {
        $this->assertSame(1, RecommendationPriority::High->sortOrder());
        $this->assertSame(2, RecommendationPriority::Medium->sortOrder());
        $this->assertSame(3, RecommendationPriority::Low->sortOrder());
    }
}
