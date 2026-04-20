<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

/**
 * Regression sentinel for the Workshop/Bundle submodule introduction.
 *
 * Workshop/Bundle was designed as a module-boundary-respecting copy of
 * the Catalog `CompositeItem` + `Recipe` pattern. Per Plan A.5, **no
 * file under `apps/api/app/Modules/Catalog/` or `apps/api/app/Modules/Menu/`
 * is modified** by the Workshop/Bundle PR. This sentinel guards both
 * directions:
 *
 * 1. It compiles — proving the Catalog domain symbols still exist with
 *    their expected shape after the Workshop/Bundle PR is merged.
 * 2. It references `PricingMode` (the F&B enum) and
 *    `CompositeItem`/`Recipe`/`RecipeLine` — a failing import would
 *    indicate an accidental rename or move that needs to be reverted.
 *
 * The wider F&B regression is covered by the full
 * `tests/{Feature,Unit}/Catalog` and `tests/{Feature,Unit}/Menu` suites,
 * which Plan A.5 mandates as a pre-merge gate (134 passing, 2 skipped).
 */
#[CoversNothing]
final class CatalogRegressionFromWorkshopBundleTest extends TestCase
{
    public function test_catalog_domain_symbols_are_reachable(): void
    {
        $this->assertTrue(class_exists(CompositeItem::class));
        $this->assertTrue(class_exists(Recipe::class));
        $this->assertTrue(class_exists(RecipeLine::class));
    }

    public function test_catalog_pricing_mode_enum_has_expected_cases(): void
    {
        // The Workshop/Bundle submodule introduced `BundlePricingMode`
        // as a parallel enum (standard | fixed_bundle). The Catalog
        // `PricingMode` enum must retain the same case names so the
        // Catalog domain contracts stay intact.
        $this->assertSame('standard', PricingMode::Standard->value);
        $this->assertSame('fixed_bundle', PricingMode::FixedBundle->value);
    }
}
