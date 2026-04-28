<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Domain\Enums\GrowthStage;
use PHPUnit\Framework\TestCase;

final class GrowthStageTest extends TestCase
{
    public function test_has_exactly_four_cases(): void
    {
        $this->assertCount(4, GrowthStage::cases());
    }

    public function test_cases_are_launch_stabilize_optimize_expand(): void
    {
        $this->assertSame('launch', GrowthStage::Launch->value);
        $this->assertSame('stabilize', GrowthStage::Stabilize->value);
        $this->assertSame('optimize', GrowthStage::Optimize->value);
        $this->assertSame('expand', GrowthStage::Expand->value);
    }

    public function test_label_returns_human_readable_name(): void
    {
        $this->assertSame('Launch', GrowthStage::Launch->label());
        $this->assertSame('Stabilize', GrowthStage::Stabilize->label());
        $this->assertSame('Optimize', GrowthStage::Optimize->label());
        $this->assertSame('Expand', GrowthStage::Expand->label());
    }

    public function test_order_returns_numeric_position(): void
    {
        $this->assertSame(1, GrowthStage::Launch->order());
        $this->assertSame(2, GrowthStage::Stabilize->order());
        $this->assertSame(3, GrowthStage::Optimize->order());
        $this->assertSame(4, GrowthStage::Expand->order());
    }
}
