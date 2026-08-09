<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FranceTaxConfigurationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_five_french_vat_configs_with_20pct_default(): void
    {
        (new CountriesSeeder)->run();

        (new FranceTaxConfigurationSeeder)->run();

        $configs = TaxConfiguration::where('country_code', 'FR')->get();
        $this->assertCount(5, $configs);

        $default = $configs->firstWhere('is_default', true);
        $this->assertNotNull($default);
        // decimal:4 cast returns 4 decimal places
        $this->assertSame('20.0000', (string) $default->percentage_rate);
        $this->assertSame('TVA_FR_20', $default->code);

        // Idempotent
        (new FranceTaxConfigurationSeeder)->run();
        $this->assertSame(5, TaxConfiguration::where('country_code', 'FR')->count());
    }

    public function test_all_five_rates_are_present_and_active(): void
    {
        (new CountriesSeeder)->run();
        (new FranceTaxConfigurationSeeder)->run();

        $codes = TaxConfiguration::where('country_code', 'FR')
            ->pluck('code')
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(
            ['TVA_FR_10', 'TVA_FR_20', 'TVA_FR_2_1', 'TVA_FR_5_5', 'TVA_FR_EXEMPT'],
            $codes,
        );

        $allActive = TaxConfiguration::where('country_code', 'FR')
            ->where('is_active', true)
            ->count();
        $this->assertSame(5, $allActive);
    }

    public function test_applicable_document_types_are_real_fiscal_category_tokens(): void
    {
        // Register G-8: the calculator keys on documents.fiscal_category, so a
        // non-category token ('QUOTATION', 'PURCHASE_INVOICE') can never match —
        // the TN seeder documents exactly this, the FR one advertised both.
        (new CountriesSeeder)->run();
        (new FranceTaxConfigurationSeeder)->run();

        $valid = array_map(
            static fn (FiscalCategory $category): string => $category->value,
            FiscalCategory::cases(),
        );

        foreach (TaxConfiguration::where('country_code', 'FR')->get() as $config) {
            $types = $config->applicable_document_types ?? [];
            $this->assertNotEmpty($types, "Config {$config->code} advertises no document types");

            foreach ($types as $type) {
                $this->assertContains(
                    $type,
                    $valid,
                    "Config {$config->code} advertises {$type}, which is not a FiscalCategory token",
                );
            }
        }
    }

    public function test_exactly_one_default_rate(): void
    {
        (new CountriesSeeder)->run();
        (new FranceTaxConfigurationSeeder)->run();

        $defaultCount = TaxConfiguration::where('country_code', 'FR')
            ->where('is_default', true)
            ->count();

        $this->assertSame(1, $defaultCount);
    }

    public function test_no_stamp_duty_rows_for_france(): void
    {
        (new CountriesSeeder)->run();
        (new FranceTaxConfigurationSeeder)->run();

        $stampCount = TaxConfiguration::where('country_code', 'FR')
            ->where('is_stamp_duty', true)
            ->count();

        $this->assertSame(0, $stampCount);
    }
}
