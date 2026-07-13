<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use Tests\TestCase;

final class LocationNodeTypeTest extends TestCase
{
    public function test_values_and_labels(): void
    {
        $this->assertSame('zone', LocationNodeType::Zone->value);
        $this->assertSame('aisle', LocationNodeType::Aisle->value);
        $this->assertSame('rack', LocationNodeType::Rack->value);
        $this->assertSame('shelf', LocationNodeType::Shelf->value);
        $this->assertSame('bin', LocationNodeType::Bin->value);
        $this->assertSame('section', LocationNodeType::Section->value);
        $this->assertCount(6, LocationNodeType::cases());

        foreach (LocationNodeType::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
