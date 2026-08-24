<?php

declare(strict_types=1);

namespace Tests\Feature\Product\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * Campaign defect N-1 (P0) —
 * `2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1`.
 *
 * The code fix stops NEW drift; only this migration repairs the rows already
 * written, and it runs unattended on every tenant DB on push. Its PREDICATE and
 * its GUARD LADDER are therefore the contract:
 *
 *   product on a LINE_ITEMS percentage config, rate disagrees  -> repaired
 *   product on a DOCUMENT_TOTAL / fixed-amount config          -> UNTOUCHED
 *   product with no configuration at all                       -> UNTOUCHED
 *   product already in agreement                               -> not even
 *                                                                 re-stamped
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the empty schema; these
 * cases build the pre-migration data by hand and then apply it.
 */
final class BackfillProductsTaxRateN1MigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1.php';

    private const GATE_TOKEN = 'PRODUCT TAX-RATE N1 BACKFILL MIGRATION:';

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // tax_configurations.country_code is an FK onto `countries`.
        (new CountriesSeeder)->run();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_a_product_whose_rate_drifted_from_its_configuration_is_repaired(): void
    {
        // The exact shape the Playwright campaign found on tenant #1: the
        // operator picked TVA 7 %, the column kept the company's 19 %.
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '19.00');

        $this->runMigration();

        $this->assertSame('7.00', $this->rateOf($product));
    }

    public function test_a_product_with_no_configuration_is_left_alone(): void
    {
        // No configuration means no statement to contradict — this product's
        // rate came from the category/company ladder and is legitimate.
        $product = $this->productOn(null, '19.00');

        $this->runMigration();

        $this->assertSame('19.00', $this->rateOf($product));
    }

    public function test_a_document_total_configuration_is_refused_not_copied(): void
    {
        // Tunisian stamp duty: applies_to = DOCUMENT_TOTAL, percentage_rate
        // NULL, fixed_amount 1.000 TND. Copying that into a VAT rate would be
        // a worse defect than the one being fixed.
        $stamp = TaxConfiguration::create([
            'country_code' => $this->company->country_code,
            'tax_type' => 'FIXED_AMOUNT',
            'name' => 'Timbre Fiscal - Facture',
            'code' => 'STAMP_TAX_INVOICE_N1',
            'percentage_rate' => null,
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'is_default' => false,
            'is_active' => true,
        ]);

        $product = $this->productOn($stamp, '19.00');

        $this->runMigration();

        $this->assertSame(
            '19.00',
            $this->rateOf($product),
            'A configuration that states no line-item percentage must never be copied into tax_rate.',
        );
    }

    public function test_a_null_rate_beside_a_configuration_is_repaired(): void
    {
        // A plain `<>` comparison would drop this row (NULL <> x is NULL);
        // the predicate uses IS DISTINCT FROM precisely so it does not.
        $product = $this->productOn($this->percentageConfig('TVA_13', '13.00'), null);

        $this->runMigration();

        $this->assertSame('13.00', $this->rateOf($product));
    }

    public function test_an_already_correct_product_is_not_even_restamped(): void
    {
        $product = $this->productOn($this->percentageConfig('TVA_19', '19.00'), '19.00');

        $before = (string) DB::table('products')->where('id', $product->id)->value('updated_at');

        $this->runMigration();

        $this->assertSame('19.00', $this->rateOf($product));
        $this->assertSame(
            $before,
            (string) DB::table('products')->where('id', $product->id)->value('updated_at'),
            'A row already in agreement must fall outside the predicate entirely.',
        );
    }

    public function test_it_is_idempotent_and_its_down_is_a_declared_no_op(): void
    {
        $drifted = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '19.00');
        $untouched = $this->productOn(null, '19.00');

        $this->assertTenantMigrationIsIrreversibleNoOp(
            self::MIGRATION,
            function (string $context) use ($drifted, $untouched): void {
                $this->assertSame('7.00', $this->rateOf($drifted), $context);
                $this->assertSame('19.00', $this->rateOf($untouched), $context);
            },
        );
    }

    public function test_it_reports_a_gate_token_line_with_the_repaired_count(): void
    {
        $this->productOn($this->percentageConfig('TVA_7', '7.00'), '19.00');
        $this->productOn($this->percentageConfig('TVA_13', '13.00'), '19.00');

        $lines = [];
        Log::listen(static function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message;
        });

        $this->runMigration();

        $gate = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, self::GATE_TOKEN),
        ));

        $this->assertCount(1, $gate, 'Exactly one gate line per tenant, or a deploy grep cannot be trusted.');
        $this->assertStringContainsString('status=ok', $gate[0]);
        $this->assertStringContainsString('repaired=2', $gate[0]);
    }

    // -------------------------------------------------------------------------

    private function runMigration(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();
    }

    private function percentageConfig(string $code, string $rate): TaxConfiguration
    {
        return TaxConfiguration::create([
            'country_code' => $this->company->country_code,
            'tax_type' => 'PERCENTAGE',
            'name' => $code,
            'code' => $code.'_N1',
            'percentage_rate' => $rate,
            'fixed_amount' => null,
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    private function productOn(?TaxConfiguration $configuration, ?string $rate): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'tax_rate' => $rate,
            'default_tax_configuration_id' => $configuration?->id,
        ]);
    }

    /**
     * Read through the model so the `decimal:2` cast applies — SQLite's numeric
     * affinity hands back `19` where PostgreSQL hands back `19.00`, and the
     * comparison under test is about the VALUE, not the driver's spelling.
     */
    private function rateOf(Product $product): ?string
    {
        $rate = $product->fresh()?->tax_rate;

        return $rate === null ? null : (string) $rate;
    }
}
