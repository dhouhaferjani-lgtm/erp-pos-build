<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Product\Application\DTOs\EffectiveMargins;
use App\Modules\Product\Domain\Enums\MarginSource;
use Tests\TestCase;

class EffectiveMarginsTest extends TestCase
{
    public function test_holds_resolved_values_and_provenance(): void
    {
        $m = new EffectiveMargins('30.00', '15.00', MarginSource::Product, null, MarginSource::Company, null, false);
        $this->assertSame('30.00', $m->target_margin);
        $this->assertSame(MarginSource::Company, $m->minimum_source);
        $this->assertFalse($m->minimum_clamped);
    }
}
