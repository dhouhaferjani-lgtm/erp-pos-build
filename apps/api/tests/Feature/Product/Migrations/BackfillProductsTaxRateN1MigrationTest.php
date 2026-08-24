<?php

declare(strict_types=1);

namespace Tests\Feature\Product\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        $gate = $this->captureGateLine();

        $this->assertStringContainsString('status=ok', $gate);
        $this->assertStringContainsString('repaired=2', $gate);
    }

    // -------------------------------------------------------------------------
    // Second half (gate r1 finding 3): unposted document lines
    // -------------------------------------------------------------------------

    public function test_an_unposted_document_line_is_repaired_and_its_document_re_totalled(): void
    {
        // The campaign tenant's real shape: a quote written from a product whose
        // rate had drifted. Conversion copies a line's tax_rate verbatim, so
        // leaving this at 19 % would mint a wrong-VAT invoice one click later.
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '7.00');
        [$document, $line] = $this->unpostedDocumentLine($product, '19.00');

        $this->runMigration();

        $this->assertSame('7.00', (string) $line->fresh()?->tax_rate);

        $fresh = $document->fresh();
        $this->assertSame(
            '7.000',
            (string) $fresh?->tax_amount,
            'The header must be re-totalled from the repaired lines, or the document contradicts itself.',
        );
        $this->assertSame('107.000', (string) $fresh?->total);
    }

    public function test_a_posted_document_line_is_never_touched(): void
    {
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '7.00');
        [, $line] = $this->unpostedDocumentLine($product, '19.00', DocumentStatus::Posted);

        $this->runMigration();

        $this->assertSame(
            '19.00',
            (string) $line->fresh()?->tax_rate,
            'A posted document has GL entries derived from its tax — a migration must not rewrite it.',
        );
    }

    public function test_a_sealed_document_line_is_never_touched(): void
    {
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '7.00');
        [, $line] = $this->unpostedDocumentLine(
            $product,
            '19.00',
            DocumentStatus::Draft,
            FiscalStatus::Sealed,
            'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
        );

        $this->runMigration();

        $this->assertSame(
            '19.00',
            (string) $line->fresh()?->tax_rate,
            'Anything hash-chained is untouchable: correcting it is a credit note, not an UPDATE.',
        );
    }

    public function test_a_free_text_line_with_no_product_is_left_alone(): void
    {
        $this->productOn($this->percentageConfig('TVA_7', '7.00'), '7.00');

        $document = $this->unpostedDocument();
        $line = DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'product_id' => null,
            'description' => 'Frais de livraison',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);

        $this->runMigration();

        $this->assertSame('19.00', (string) $line->fresh()?->tax_rate);
    }

    public function test_the_gate_line_reports_the_document_counters(): void
    {
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '19.00');
        $this->unpostedDocumentLine($product, '19.00');

        $gate = $this->captureGateLine();

        $this->assertStringContainsString('status=ok', $gate);
        $this->assertStringContainsString('repaired=1', $gate);
        $this->assertStringContainsString('doc_lines=1', $gate);
        $this->assertStringContainsString('docs_retotalled=1', $gate);
    }

    // -------------------------------------------------------------------------
    // Guard ladder (gate r1 finding 4): the non-happy branches
    // -------------------------------------------------------------------------

    public function test_a_missing_table_is_reported_as_skipped_not_thrown(): void
    {
        // `tenants:migrate` runs this unattended across the fleet. A tenant
        // whose schema is not there yet must produce an ACTIONABLE line, not a
        // silent return and not an exception that kills the run.
        Schema::rename('tax_configurations', 'tax_configurations_hidden_for_test');

        try {
            $gate = $this->captureGateLine();
        } finally {
            Schema::rename('tax_configurations_hidden_for_test', 'tax_configurations');
        }

        $this->assertStringContainsString('status=skipped', $gate);
        $this->assertStringContainsString('reason=tax-configurations-table-absent', $gate);
    }

    public function test_a_failure_is_reported_as_failed_and_never_thrown(): void
    {
        // The catch is what stops one tenant from bricking the fleet run, but
        // it also means a failed tenant is never retried — so the FAILED line
        // is the only signal, and the promotion checklist greps for it. If this
        // branch ever throws instead, that grep is worthless.
        $product = $this->productOn($this->percentageConfig('TVA_7', '7.00'), '19.00');
        $this->unpostedDocumentLine($product, '19.00');

        // Break the second half specifically. Dropping a COLUMN the predicate
        // reads (rather than a whole table) gets past the `Schema::hasTable`
        // guards and fails inside the statement — the shape a genuine schema
        // drift takes. RefreshDatabase's transaction rolls the DDL back.
        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('fiscal_hash');
        });

        $gate = $this->captureGateLine();

        $this->assertStringContainsString('status=FAILED', $gate);
        $this->assertStringContainsString('reason=exception', $gate);
        $this->assertSame(
            '7.00',
            (string) $product->fresh()?->tax_rate,
            'The product half had already committed — its own savepoint is not rolled back by the second half failing.',
        );
    }

    // -------------------------------------------------------------------------

    private function runMigration(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();
    }

    /**
     * Run the migration and return the single gate-token line it logged. The
     * token line IS the deploy-gate contract — a checklist greps for it — so
     * every branch that can be reached in production is asserted through here.
     */
    private function captureGateLine(): string
    {
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

        return $gate[0];
    }

    private function unpostedDocument(
        DocumentStatus $status = DocumentStatus::Draft,
        FiscalStatus $fiscalStatus = FiscalStatus::Draft,
        ?string $fiscalHash = null,
    ): Document {
        return Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Quote,
            'status' => $status,
            'fiscal_status' => $fiscalStatus,
            'fiscal_hash' => $fiscalHash,
            // PostgreSQL enforces `chk_fiscal_mandatory_core`: a SEALED fiscal
            // document must carry date + number + total + currency + hash +
            // chain_sequence. SQLite has no such constraint, so a fixture that
            // omits it passes there and dies on PG — build the sealed row the
            // way the database insists it exists.
            'chain_sequence' => $fiscalHash !== null ? 1 : null,
            'currency' => $this->company->currency,
            'document_date' => now(),
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
    }

    /**
     * A document with one product line at the given (stale) rate.
     *
     * @return array{0: Document, 1: DocumentLine}
     */
    private function unpostedDocumentLine(
        Product $product,
        string $staleRate,
        DocumentStatus $status = DocumentStatus::Draft,
        FiscalStatus $fiscalStatus = FiscalStatus::Draft,
        ?string $fiscalHash = null,
    ): array {
        $document = $this->unpostedDocument($status, $fiscalStatus, $fiscalHash);

        $line = DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'description' => (string) $product->name,
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => $staleRate,
            'line_total' => '100.000',
        ]);

        return [$document, $line];
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
