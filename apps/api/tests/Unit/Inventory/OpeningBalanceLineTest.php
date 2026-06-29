<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use InvalidArgumentException;
use Tests\TestCase;

final class OpeningBalanceLineTest extends TestCase
{
    public function test_canonicalizes_quantity_and_cost_to_scale(): void
    {
        $line = OpeningBalanceLine::make('p-1', null, 'loc-1', '10', '5.1', currencyScale: 3);

        $this->assertSame('10.0000', $line->quantity);
        $this->assertSame('5.100', $line->unitCost);
    }

    public function test_rejects_over_scale_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', '10.00001', '5.000', currencyScale: 3);
    }

    public function test_rejects_negative_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', '10', '-1.000', currencyScale: 3);
    }

    public function test_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpeningBalanceLine::make('p-1', null, 'loc-1', 'abc', '5.000', currencyScale: 3);
    }
}
