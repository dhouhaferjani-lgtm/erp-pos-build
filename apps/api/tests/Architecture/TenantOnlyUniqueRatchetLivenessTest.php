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
            'new tenant-only unique on catalogue table products (index liveness_products_tenant_barcode) — add company_id to the key, or add it to the baseline with a `waiver` reason field',
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
}
