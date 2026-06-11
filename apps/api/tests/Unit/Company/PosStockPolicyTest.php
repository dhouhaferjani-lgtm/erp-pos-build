<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use PHPUnit\Framework\TestCase;

final class PosStockPolicyTest extends TestCase
{
    public function test_values(): void
    {
        self::assertSame('block', PosStockPolicy::Block->value);
        self::assertSame('warn', PosStockPolicy::Warn->value);
        self::assertSame('off', PosStockPolicy::Off->value);
    }

    public function test_default_for_vertical_is_off_for_menu_verticals(): void
    {
        self::assertSame(PosStockPolicy::Off, PosStockPolicy::defaultForVertical(Vertical::Restaurant));
        self::assertSame(PosStockPolicy::Off, PosStockPolicy::defaultForVertical(Vertical::CoffeeShop));
    }

    public function test_default_for_vertical_is_block_for_stock_verticals(): void
    {
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Retail));
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Parapharmacy));
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Mechanic));
    }
}
