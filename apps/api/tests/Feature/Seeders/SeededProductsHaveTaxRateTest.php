<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ParapharmacySeeder;
use Database\Seeders\TunisianParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T15 — Every demo-seeder product must carry an explicit, country-appropriate
 *        tax_rate so the demo shows correct (non-zero) tax.
 *
 * Coverage:
 *   - ParapharmacySeeder (FR): all products get 20.00; zero NULLs.
 *   - TunisianParapharmacySeeder (TN): all products (explicit + factory-100) get
 *     19.00; zero NULLs.
 *
 * ParapharmacyMultiBranchSeeder and CoffeeShopSeeder are verified by code reading
 * (both already set explicit rates — 20.00 and 7.00 respectively) and are omitted
 * from this test because they are heavy seeders that would exhaust the CI harness.
 */
final class SeededProductsHaveTaxRateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ParapharmacySeeder (FR) — no product may have a NULL tax_rate, and every
     * product must carry the French standard rate (20.00) because the seeder's
     * calculatePricing() was fixed in T15 to emit 20.00 for all categories.
     */
    public function test_parapharmacy_seeder_products_all_have_fr_tax_rate(): void
    {
        $this->artisan('db:seed', ['--class' => ParapharmacySeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            Product::whereNull('tax_rate')->count(),
            'No product created by ParapharmacySeeder may have a NULL tax_rate'
        );

        $this->assertTrue(
            Product::where('tax_rate', '20.00')->exists(),
            'At least one ParapharmacySeeder product must carry the 20.00 FR standard rate'
        );

        // All products in this seeder must be 20.00 (the only rate emitted
        // after the T15 fix — the old 5.50 branch was removed).
        $this->assertSame(
            0,
            Product::where('tax_rate', '!=', '20.00')->count(),
            'After T15 fix, every ParapharmacySeeder product must have tax_rate = 20.00'
        );
    }

    /**
     * TunisianParapharmacySeeder (TN) — no product may have a NULL tax_rate, and
     * the TN standard rate (19.00) must appear on both explicitly-created products
     * and the 100 factory-created products (which previously defaulted to FR rates).
     *
     * Requires DatabaseSeeder to run first (creates active tenant + countries).
     */
    public function test_tunisian_parapharmacy_seeder_products_all_have_tn_tax_rate(): void
    {
        // TunisianParapharmacySeeder attaches to the first active tenant, which
        // DatabaseSeeder creates (with countries already seeded).
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertExitCode(0);

        // Capture existing product IDs so we can isolate the ones TunisianParapharmacySeeder creates.
        $existingIds = Product::pluck('id');

        $this->artisan('db:seed', ['--class' => TunisianParapharmacySeeder::class, '--force' => true])
            ->assertExitCode(0);

        // Isolate products created by TunisianParapharmacySeeder (those absent before the seed).
        $newProductIds = Product::whereNotIn('id', $existingIds)->pluck('id');

        $this->assertGreaterThan(
            0,
            $newProductIds->count(),
            'TunisianParapharmacySeeder must create at least one product'
        );

        $this->assertSame(
            0,
            Product::whereIn('id', $newProductIds)->whereNull('tax_rate')->count(),
            'No product created by TunisianParapharmacySeeder may have a NULL tax_rate'
        );

        $this->assertTrue(
            Product::whereIn('id', $newProductIds)->where('tax_rate', '19.00')->exists(),
            'TunisianParapharmacySeeder products must include the 19.00 TN standard rate'
        );

        $this->assertSame(
            0,
            Product::whereIn('id', $newProductIds)->where('tax_rate', '!=', '19.00')->count(),
            'All products seeded by TunisianParapharmacySeeder must carry tax_rate = 19.00'
        );
    }
}
