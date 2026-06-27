<?php
declare(strict_types=1);
namespace Tests\Unit\Product\Enums;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Enums\PricingMode;
use Tests\TestCase;

class PricingModeTest extends TestCase
{
    public function test_pricing_mode_values(): void
    {
        $this->assertSame('auto', PricingMode::Auto->value);
        $this->assertSame('manual', PricingMode::Manual->value);
    }

    public function test_margin_source_values(): void
    {
        $this->assertSame('product', MarginSource::Product->value);
        $this->assertSame('category', MarginSource::Category->value);
        $this->assertSame('company', MarginSource::Company->value);
        $this->assertSame('default', MarginSource::DefaultFallback->value);
    }
}
