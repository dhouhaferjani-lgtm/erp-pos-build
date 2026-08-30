<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\TenantOnlyUniqueBaselineEntry;
use Tests\Architecture\Support\TenantOnlyUniqueIndex;
use Tests\Architecture\Support\TenantOnlyUniqueIndexScanner;
use Tests\Architecture\Support\TenantOnlyUniqueRatchetChecker;
use Tests\TestCase;

final class TenantOnlyUniqueRatchetLivenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG-only ratchet — gated by the backend-test-pgsql lane');
        }
    }

    #[Test]
    public function tenant_only_unique_growth_is_reported_with_remediation(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX liveness_products_tenant_barcode ON products (tenant_id, barcode)',
        );

        $liveIndexes = (new TenantOnlyUniqueIndexScanner)->scan(
            DB::connection(),
            TenantOnlyUniqueOnCatalogueTablesRatchetTest::CATALOGUE_TABLES,
        );
        $report = (new TenantOnlyUniqueRatchetChecker)->check($liveIndexes, []);

        self::assertStringContainsString(
            'new tenant-only unique on catalogue table products (index liveness_products_tenant_barcode) — add company_id to the key, or re-pin reviewed legacy debt as {"key": ...}; `waiver` is only for a legitimately tenant-global key',
            $report->message(),
        );
    }

    #[Test]
    public function company_scoped_unique_index_is_not_reported(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX liveness_payment_methods_company_code ON payment_methods (tenant_id, company_id, code)',
        );

        $liveIndexes = (new TenantOnlyUniqueIndexScanner)->scan(
            DB::connection(),
            TenantOnlyUniqueOnCatalogueTablesRatchetTest::CATALOGUE_TABLES,
        );
        $matching = array_values(array_filter(
            $liveIndexes,
            static fn (TenantOnlyUniqueIndex $index): bool => $index->indexName === 'liveness_payment_methods_company_code',
        ));

        self::assertSame([], $matching);
    }

    #[Test]
    public function nonexistent_in_memory_baseline_entry_is_reported_as_stale(): void
    {
        $entry = new TenantOnlyUniqueBaselineEntry(
            key: 'products|liveness_missing_index|tenant_id,barcode',
            waiver: 'liveness-only nonexistent key',
        );

        $report = (new TenantOnlyUniqueRatchetChecker)->check([], [$entry]);

        self::assertStringContainsString(
            'baseline entry no longer in schema — remove it (a lane fixed it): '.$entry->key,
            $report->message(),
        );
    }

    #[Test]
    public function unique_without_tenant_id_on_a_catalogue_table_is_reported(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX liveness_products_sku_without_tenant ON products (sku)',
        );

        $liveIndexes = (new TenantOnlyUniqueIndexScanner)->scan(
            DB::connection(),
            TenantOnlyUniqueOnCatalogueTablesRatchetTest::CATALOGUE_TABLES,
        );
        $report = (new TenantOnlyUniqueRatchetChecker)->check($liveIndexes, []);

        self::assertStringContainsString(
            'new tenant-only unique on catalogue table products (index liveness_products_sku_without_tenant)',
            $report->message(),
        );
    }

    #[Test]
    public function qualifying_unique_on_an_unclassified_table_reports_the_classification_remediation(): void
    {
        DB::statement('CREATE TABLE liveness_unclassified_catalogue (id uuid PRIMARY KEY, sku text NOT NULL)');
        DB::statement(
            'CREATE UNIQUE INDEX liveness_unclassified_catalogue_sku_unique ON liveness_unclassified_catalogue (sku)',
        );

        $violations = TenantOnlyUniqueOnCatalogueTablesRatchetTest::unclassifiedTableViolations(
            (new TenantOnlyUniqueIndexScanner)->scanAll(DB::connection()),
        );

        self::assertContains(
            'classify table liveness_unclassified_catalogue: catalogue (company-owned) or excluded (tenant-global, say why)',
            $violations,
        );
    }
}
