<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Modules\Service\Domain\Enums\PricingType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for PricingType enum.
 *
 * Service pricing can be:
 * - flat_rate: Fixed price per service
 * - hourly: Price based on time spent
 * - percentage: Price as percentage of another amount (e.g., parts markup)
 */
class PricingTypeTest extends TestCase
{
    #[Test]
    public function it_has_flat_rate_case(): void
    {
        $this->assertEquals('flat_rate', PricingType::FlatRate->value);
    }

    #[Test]
    public function it_has_hourly_case(): void
    {
        $this->assertEquals('hourly', PricingType::Hourly->value);
    }

    #[Test]
    public function it_has_percentage_case(): void
    {
        $this->assertEquals('percentage', PricingType::Percentage->value);
    }

    #[Test]
    public function it_returns_all_values(): void
    {
        $values = PricingType::values();

        $this->assertCount(3, $values);
        $this->assertContains('flat_rate', $values);
        $this->assertContains('hourly', $values);
        $this->assertContains('percentage', $values);
    }

    #[Test]
    public function it_can_be_created_from_string(): void
    {
        $flatRate = PricingType::from('flat_rate');
        $hourly = PricingType::from('hourly');
        $percentage = PricingType::from('percentage');

        $this->assertEquals(PricingType::FlatRate, $flatRate);
        $this->assertEquals(PricingType::Hourly, $hourly);
        $this->assertEquals(PricingType::Percentage, $percentage);
    }

    #[Test]
    public function it_returns_human_readable_label(): void
    {
        $this->assertEquals('Flat Rate', PricingType::FlatRate->label());
        $this->assertEquals('Hourly', PricingType::Hourly->label());
        $this->assertEquals('Percentage', PricingType::Percentage->label());
    }
}
