<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Database\Factories\CatalogCartItemFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9 regression coverage: seeders and factories must emit canonical,
 * scale-correct numeric strings for monetary (currency scale) and quantity
 * (scale 4) fields — never raw floats like 8.0 / 8.000 that can drift through
 * IEEE 754 (e.g. 5.000 -> 4.999).
 *
 * These factories use null foreign keys in their definition, so Factory::raw()
 * resolves without touching the database. raw() returns the merged attribute
 * array BEFORE Eloquent re-applies decimal casts, so we are asserting exactly
 * what the factory itself produced.
 */
final class FactoryCanonicalScaleTest extends TestCase
{
    /**
     * A canonical fixed-scale decimal string: optional minus, digits, a dot,
     * then EXACTLY $scale fractional digits.
     */
    private function assertCanonicalScale(string $value, int $scale, string $context): void
    {
        $pattern = '/^-?\d+\.\d{'.$scale.'}$/';
        $this->assertMatchesRegularExpression(
            $pattern,
            $value,
            "{$context} must be a canonical {$scale}-decimal numeric string, got '{$value}'",
        );
    }

    #[Test]
    public function product_factory_emits_canonical_three_decimal_money_strings(): void
    {
        $attributes = Product::factory()->raw();

        // Currency-scale money fields (TND default => scale 3).
        foreach (['cost_price', 'sale_price', 'purchase_price'] as $field) {
            $this->assertIsString($attributes[$field], "{$field} must be a string");
            $this->assertCanonicalScale($attributes[$field], 3, "Product::{$field}");
        }

        // tax_rate is a rate, stored at scale 2.
        $this->assertCanonicalScale($attributes['tax_rate'], 2, 'Product::tax_rate');
    }

    #[Test]
    public function product_factory_currency_state_rescales_money_to_iso_scale(): void
    {
        // EUR => scale 2, JPY => scale 0, TND => scale 3.
        $eur = Product::factory()->currency('EUR')->raw();
        $jpy = Product::factory()->currency('JPY')->raw();

        $this->assertCanonicalScale($eur['sale_price'], 2, 'EUR Product::sale_price');
        $this->assertCanonicalScale($eur['cost_price'], 2, 'EUR Product::cost_price');

        // JPY scale 0 => no fractional part.
        $this->assertMatchesRegularExpression('/^-?\d+$/', $jpy['sale_price']);
    }

    #[Test]
    public function receipt_payment_factory_emits_canonical_three_decimal_amount(): void
    {
        $attributes = ReceiptPayment::factory()->raw();

        $this->assertIsString($attributes['amount']);
        $this->assertCanonicalScale($attributes['amount'], 3, 'ReceiptPayment::amount');
    }

    #[Test]
    public function catalog_cart_item_factory_emits_canonical_scales(): void
    {
        $attributes = CatalogCartItemFactory::new()->raw();

        // unit_price: money scale 3; quantity: scale 4.
        $this->assertCanonicalScale($attributes['unit_price'], 3, 'CatalogCartItem::unit_price');
        $this->assertCanonicalScale($attributes['quantity'], 4, 'CatalogCartItem::quantity');
    }

    #[Test]
    public function bcformat_baseline_proves_no_float_drift_for_factory_values(): void
    {
        // The exact failure mode the Phase 9 conversion guards against.
        $this->assertSame('8.000', CurrencyScale::bcformat(8.0, 3));
        $this->assertSame('8.0000', CurrencyScale::bcformat(8.0, 4));
        $this->assertSame('5.000', CurrencyScale::bcformat('5', 3));
    }
}
