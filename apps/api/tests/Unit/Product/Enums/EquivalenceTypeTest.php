<?php

declare(strict_types=1);

namespace Tests\Unit\Product\Enums;

use App\Modules\Product\Domain\Enums\EquivalenceType;
use App\Modules\Product\Domain\Enums\BrandSource;
use Tests\TestCase;

class EquivalenceTypeTest extends TestCase
{
    public function test_equivalence_type_values(): void
    {
        $this->assertSame('generic', EquivalenceType::Generic->value);
        $this->assertSame('therapeutic', EquivalenceType::Therapeutic->value);
        $this->assertSame('brand_alt', EquivalenceType::BrandAlt->value);
    }

    public function test_brand_source_values(): void
    {
        $values = array_map(fn ($c) => $c->value, BrandSource::cases());
        $this->assertSame(['user', 'enriched'], $values);
    }
}
