<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T1.0 — Large-catalog fixture for Tier-2 perf assertions.
 *
 * Phase 0 of `pos-offline-first-hardening.md` (line 42) calls for "5000-product
 * seed in a clean tenant" so subsequent smoke tests can assert behavior at
 * realistic catalog scale. Bumping the existing 1000-product distribution
 * uniformly would regress every CI run that depends on the fixed count, so
 * T1.0 introduces a configurable SCALE multiplier (default 1, env-var override
 * via `PARAPHARMACY_SEEDER_SCALE`) that preserves the existing default while
 * letting Tier-2 / smoke runs request a 5x or 10x catalog on demand.
 */
final class ParapharmacySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Always clear the env override so subsequent tests in the same
        // process don't see a leaked value (T1.0 Codex preempt c).
        putenv('PARAPHARMACY_SEEDER_SCALE');
        unset($_ENV['PARAPHARMACY_SEEDER_SCALE']);
        unset($_SERVER['PARAPHARMACY_SEEDER_SCALE']);

        parent::tearDown();
    }

    public function test_t1_0_default_scale_produces_1000_products(): void
    {
        $this->seed(ParapharmacySeeder::class);

        // Hardcoded category distribution totals 1000:
        //   supplement 350 + cosmetic 250 + medical_device 100 + herbal 150
        //   + baby_care 100 + sports_nutrition 50 = 1000
        $this->assertSame(1000, Product::count(), 'Default ParapharmacySeeder must produce exactly 1000 products');
    }

    public function test_t1_0_scale_5_via_env_var_produces_5000_products(): void
    {
        // PARAPHARMACY_SEEDER_SCALE=5 must multiply every category count by 5.
        putenv('PARAPHARMACY_SEEDER_SCALE=5');
        $_ENV['PARAPHARMACY_SEEDER_SCALE'] = '5';

        $this->seed(ParapharmacySeeder::class);

        $this->assertSame(5000, Product::count(), 'SCALE=5 must produce 5x the default count');
    }

    public function test_t1_0_scale_5_preserves_category_distribution(): void
    {
        // Category-level invariant: 5x scale preserves the proportional mix.
        // Supplement is the largest category (350 default → 1750 at 5x); a
        // regression that multiplied only some categories or applied the
        // multiplier non-uniformly would fail this.
        putenv('PARAPHARMACY_SEEDER_SCALE=5');
        $_ENV['PARAPHARMACY_SEEDER_SCALE'] = '5';

        $this->seed(ParapharmacySeeder::class);

        // Category is stored on ParapharmacyProductMetadata, not Product
        // directly. The seeder creates one metadata row per product (see
        // ParapharmacySeeder::createProduct).
        $supplementCount = ParapharmacyProductMetadata::where('category', 'supplement')->count();
        $this->assertSame(1750, $supplementCount, 'Supplement category at SCALE=5 must be 350 * 5 = 1750');
    }

    public function test_t1_0_invalid_scale_falls_back_to_default(): void
    {
        // A non-numeric or sub-1 scale value must NOT silently produce zero
        // products or crash. The seeder treats invalid input as the default
        // scale of 1.
        putenv('PARAPHARMACY_SEEDER_SCALE=not-a-number');
        $_ENV['PARAPHARMACY_SEEDER_SCALE'] = 'not-a-number';

        $this->seed(ParapharmacySeeder::class);

        $this->assertSame(1000, Product::count(), 'Invalid SCALE must fall back to default 1000');
    }
}
