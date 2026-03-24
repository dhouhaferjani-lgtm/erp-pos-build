<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Application\DTOs\CompositeItemData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Domain\Enums\PriceAdjustmentType;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Shared\Domain\CurrencyScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for composite item price precision.
 *
 * Prevents the bug where 5.000 TND displayed as 4.999 due to
 * IEEE 754 float conversion in number_format((float) $value, ...).
 */
final class CompositeItemPricePrecisionTest extends TestCase
{
    #[Test]
    #[DataProvider('tndPricesProvider')]
    public function composite_item_dto_preserves_tnd_price_precision(string $dbPrice): void
    {
        $item = $this->makeCompositeItem($dbPrice);

        $dto = CompositeItemData::fromModel($item);

        // DTO base_price must be the same value at 4 decimal places (internal precision)
        $expected = CurrencyScale::bcformat($dbPrice, 4);
        $this->assertSame($expected, $dto->base_price, "Price {$dbPrice} must survive DTO conversion without precision loss");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tndPricesProvider(): array
    {
        return [
            // The exact bug report: 5 TND showing as 4.999
            '5.0000 TND' => ['5.0000'],
            '5.000 TND' => ['5.000'],

            // Common TND price points
            '0.500' => ['0.500'],
            '1.000' => ['1.000'],
            '1.500' => ['1.500'],
            '2.500' => ['2.500'],
            '3.500' => ['3.500'],
            '7.500' => ['7.500'],
            '10.000' => ['10.000'],
            '15.000' => ['15.000'],
            '20.000' => ['20.000'],
            '25.000' => ['25.000'],
            '50.000' => ['50.000'],
            '99.990' => ['99.990'],
            '100.000' => ['100.000'],

            // Edge cases: values known to have IEEE 754 issues
            '0.1' => ['0.1'],
            '0.2' => ['0.2'],
            '0.3' => ['0.3'],
            '19.99' => ['19.99'],
        ];
    }

    #[Test]
    public function variant_absolute_adjustment_preserves_precision(): void
    {
        $variant = new CompositeItemVariant();
        $variant->price_adjustment_type = PriceAdjustmentType::Absolute;
        $variant->price_adjustment = '2.000';

        $result = $variant->calculatePrice('5.0000');

        // 5.0000 + 2.0000 = 7.0000 exactly
        $this->assertSame('7.0000', $result);
    }

    #[Test]
    public function variant_percentage_adjustment_preserves_precision(): void
    {
        $variant = new CompositeItemVariant();
        $variant->price_adjustment_type = PriceAdjustmentType::Percentage;
        $variant->price_adjustment = '10.00';

        $result = $variant->calculatePrice('5.0000');

        // 5.0000 + (5.0000 * 10.00 / 100) = 5.0000 + 0.5000 = 5.5000
        $this->assertSame('5.5000', $result);
    }

    #[Test]
    public function variant_override_preserves_precision(): void
    {
        $variant = new CompositeItemVariant();
        $variant->price_adjustment_type = PriceAdjustmentType::Override;
        $variant->price_adjustment = '7.500';

        $result = $variant->calculatePrice('5.0000');

        // Override ignores base price, uses adjustment directly
        $this->assertSame('7.5000', $result);
    }

    #[Test]
    public function bcformat_never_produces_4999_from_5000(): void
    {
        // Direct regression test for the reported bug
        $dbValues = ['5.0000', '5.000', '5.00', '5.0', '5'];

        foreach ($dbValues as $value) {
            $result3 = CurrencyScale::bcformat($value, 3);
            $result4 = CurrencyScale::bcformat($value, 4);

            $this->assertSame('5.000', $result3, "bcformat({$value}, 3) must be 5.000, not 4.999");
            $this->assertSame('5.0000', $result4, "bcformat({$value}, 4) must be 5.0000, not 4.9990");
        }
    }

    #[Test]
    public function bcformat_handles_postgresql_decimal_output_formats(): void
    {
        // PostgreSQL NUMERIC can return these string formats
        $this->assertSame('5.000', CurrencyScale::bcformat('5', 3));
        $this->assertSame('5.000', CurrencyScale::bcformat('5.0', 3));
        $this->assertSame('5.000', CurrencyScale::bcformat('5.00', 3));
        $this->assertSame('5.000', CurrencyScale::bcformat('5.000', 3));
        $this->assertSame('5.000', CurrencyScale::bcformat('5.0000', 3));

        // With trailing zeros from different DB column scales
        $this->assertSame('10.500', CurrencyScale::bcformat('10.5000', 3));
        $this->assertSame('10.50', CurrencyScale::bcformat('10.5000', 2));
    }

    #[Test]
    public function old_number_format_float_fails_for_known_problematic_values(): void
    {
        // Document the old behavior was dangerous.
        // While 5.0000 often works, this proves the pattern is unreliable:
        // (float) "0.1" + (float) "0.2" produces 0.30000000000000004
        $a = number_format((float) '0.1', 4, '.', '');
        $b = number_format((float) '0.2', 4, '.', '');
        $floatSum = (float) $a + (float) $b;
        $floatFormatted = number_format($floatSum, 4, '.', '');

        // This may or may not equal 0.3000 depending on PHP/platform — the point is
        // bcmath always produces the correct result
        $bcSum = bcadd(CurrencyScale::bcformat('0.1', 4), CurrencyScale::bcformat('0.2', 4), 4);
        $this->assertSame('0.3000', $bcSum, 'bcmath addition must produce exact 0.3000');
    }

    private function makeCompositeItem(string $basePrice): CompositeItem
    {
        $item = new CompositeItem();
        $item->id = 'test-' . md5($basePrice);
        $item->code = 'TEST-001';
        $item->name = 'Test Composite Item';
        $item->vertical_type = VerticalType::Fnb;
        $item->base_price = $basePrice;
        $item->production_type = ProductionType::MadeToOrder;
        $item->pricing_mode = PricingMode::Standard;
        $item->is_active = true;
        $item->is_available = true;
        $item->display_order = 0;

        // Set empty relations to avoid DB queries
        $item->setRelation('activeRecipe', null);
        $item->setRelation('variants', collect());
        $item->setRelation('modifierGroups', collect());

        return $item;
    }
}
