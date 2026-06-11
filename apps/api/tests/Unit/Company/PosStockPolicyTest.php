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

    /**
     * Exhaustive map over every vertical. The default derives from 'Menu' being
     * in the vertical's default modules — if a vertical ever gains/loses Menu,
     * its stock-enforcement default silently flips with it. This pin turns that
     * silent flip into a test failure that forces a deliberate decision.
     */
    public function test_full_vertical_to_policy_map(): void
    {
        $expectedOff = [Vertical::Restaurant, Vertical::CoffeeShop];

        foreach (Vertical::cases() as $vertical) {
            $expected = in_array($vertical, $expectedOff, true)
                ? PosStockPolicy::Off
                : PosStockPolicy::Block;

            self::assertSame(
                $expected,
                PosStockPolicy::defaultForVertical($vertical),
                "Unexpected default policy for vertical '{$vertical->value}'",
            );
        }
    }
}
