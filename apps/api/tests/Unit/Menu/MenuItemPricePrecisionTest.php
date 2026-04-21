<?php

declare(strict_types=1);

namespace Tests\Unit\Menu;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Menu\Application\DTOs\MenuItemData;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for menu item price precision in POS display.
 *
 * The POS fetches active menu items via MenuItemData DTOs.
 * The effective_price field is what the POS displays and uses for cart calculations.
 * Any precision loss here directly causes wrong prices shown to customers.
 */
final class MenuItemPricePrecisionTest extends TestCase
{
    #[Test]
    #[DataProvider('compositeItemPricesProvider')]
    public function composite_item_effective_price_has_no_precision_loss(string $basePrice, string $expectedAtScale4): void
    {
        $item = $this->makeCompositeItemWithPivot($basePrice);

        $dto = MenuItemData::fromCompositeItemPivot($item);

        $this->assertSame($expectedAtScale4, $dto->effective_price, "Composite item base_price={$basePrice} must produce effective_price={$expectedAtScale4}");
        $this->assertSame($expectedAtScale4, $dto->base_price);
        $this->assertNull($dto->override_price);
    }

    #[Test]
    public function composite_item_override_price_takes_precedence(): void
    {
        $item = $this->makeCompositeItemWithPivot('5.0000', '7.5000');

        $dto = MenuItemData::fromCompositeItemPivot($item);

        $this->assertSame('7.5000', $dto->effective_price);
        $this->assertSame('5.0000', $dto->base_price);
        $this->assertSame('7.5000', $dto->override_price);
    }

    #[Test]
    public function composite_item_override_price_preserves_precision(): void
    {
        // Override price is stored as decimal(15,4) in the pivot table
        $item = $this->makeCompositeItemWithPivot('10.0000', '5.0000');

        $dto = MenuItemData::fromCompositeItemPivot($item);

        // 5.0000 must not become 4.9990 or 4.999
        $this->assertSame('5.0000', $dto->effective_price);
    }

    #[Test]
    #[DataProvider('productPricesProvider')]
    public function product_effective_price_has_no_precision_loss(string $salePrice, string $expectedAtScale4): void
    {
        $product = $this->makeProductWithPivot($salePrice);

        $dto = MenuItemData::fromProductPivot($product);

        $this->assertSame($expectedAtScale4, $dto->effective_price, "Product sale_price={$salePrice} must produce effective_price={$expectedAtScale4}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function compositeItemPricesProvider(): array
    {
        return [
            // THE bug: 5 TND was showing as 4.999
            '5.0000 TND (the reported bug)' => ['5.0000', '5.0000'],
            '5.000 TND (3 decimal input)' => ['5.000', '5.0000'],

            // Common TND prices
            '0.500' => ['0.500', '0.5000'],
            '1.000' => ['1.000', '1.0000'],
            '2.500' => ['2.500', '2.5000'],
            '7.500' => ['7.500', '7.5000'],
            '10.000' => ['10.000', '10.0000'],
            '15.000' => ['15.000', '15.0000'],
            '25.000' => ['25.000', '25.0000'],
            '99.990' => ['99.990', '99.9900'],

            // EUR-style prices
            '19.99' => ['19.99', '19.9900'],
            '0.01' => ['0.01', '0.0100'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function productPricesProvider(): array
    {
        return [
            '5.000 TND' => ['5.000', '5.0000'],
            '0.500 TND' => ['0.500', '0.5000'],
            '10.000 TND' => ['10.000', '10.0000'],
            '19.99 EUR' => ['19.99', '19.9900'],
            '0.01 EUR' => ['0.01', '0.0100'],
        ];
    }

    private function makeCompositeItemWithPivot(string $basePrice, ?string $overridePrice = null): CompositeItem
    {
        $item = new CompositeItem;
        $item->id = 'ci-'.md5($basePrice);
        $item->code = 'CI-001';
        $item->name = 'Test Composite';
        $item->vertical_type = VerticalType::Fnb;
        $item->base_price = $basePrice;
        $item->production_type = ProductionType::MadeToOrder;
        $item->pricing_mode = PricingMode::Standard;
        $item->is_active = true;
        $item->is_available = true;
        $item->display_order = 0;
        $item->image_url = null;
        $item->tax_rate = null;

        $item->setRelation('modifierGroups', collect());

        // Simulate the pivot from menu_category_items
        $pivot = new Pivot;
        $pivot->setAttribute('id', 'pivot-'.md5($basePrice));
        $pivot->setAttribute('override_price', $overridePrice);
        $pivot->setAttribute('display_order', 0);
        $pivot->setAttribute('is_available', true);
        $item->setRelation('pivot', $pivot);

        return $item;
    }

    private function makeProductWithPivot(string $salePrice, ?string $overridePrice = null): Product
    {
        $product = new Product;
        $product->id = 'p-'.md5($salePrice);
        $product->sku = 'SKU-001';
        $product->name = 'Test Product';
        $product->sale_price = $salePrice;
        $product->tax_rate = null;

        $pivot = new Pivot;
        $pivot->setAttribute('id', 'pivot-'.md5($salePrice));
        $pivot->setAttribute('override_price', $overridePrice);
        $pivot->setAttribute('display_order', 0);
        $pivot->setAttribute('is_available', true);
        $product->setRelation('pivot', $pivot);

        return $product;
    }
}
