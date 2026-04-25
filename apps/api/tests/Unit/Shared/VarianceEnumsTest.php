<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Tests\TestCase;

final class VarianceEnumsTest extends TestCase
{
    public function test_variance_direction_values_are_lowercase(): void
    {
        $this->assertSame('over', VarianceDirection::Over->value);
        $this->assertSame('under', VarianceDirection::Under->value);
        $this->assertSame('balanced', VarianceDirection::Balanced->value);
    }

    public function test_variance_direction_from_signed_amount(): void
    {
        $this->assertSame(VarianceDirection::Balanced, VarianceDirection::fromSignedAmount('0.0000'));
        $this->assertSame(VarianceDirection::Over, VarianceDirection::fromSignedAmount('0.0001'));
        $this->assertSame(VarianceDirection::Under, VarianceDirection::fromSignedAmount('-0.0001'));
    }

    public function test_variance_severity_values_are_lowercase(): void
    {
        $this->assertSame('info', VarianceSeverity::Info->value);
        $this->assertSame('warning', VarianceSeverity::Warning->value);
        $this->assertSame('critical', VarianceSeverity::Critical->value);
    }

    public function test_should_email_at_threshold(): void
    {
        $this->assertFalse(VarianceSeverity::shouldEmailAt('none', VarianceSeverity::Critical));
        $this->assertTrue(VarianceSeverity::shouldEmailAt('critical', VarianceSeverity::Critical));
        $this->assertFalse(VarianceSeverity::shouldEmailAt('critical', VarianceSeverity::Warning));
        $this->assertTrue(VarianceSeverity::shouldEmailAt('warning', VarianceSeverity::Warning));
        $this->assertTrue(VarianceSeverity::shouldEmailAt('warning', VarianceSeverity::Critical));
        $this->assertTrue(VarianceSeverity::shouldEmailAt('info', VarianceSeverity::Info));
        $this->assertTrue(VarianceSeverity::shouldEmailAt('info', VarianceSeverity::Warning));
    }
}
