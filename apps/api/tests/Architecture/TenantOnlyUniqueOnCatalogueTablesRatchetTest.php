<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\TenantOnlyUniqueBaseline;
use Tests\Architecture\Support\TenantOnlyUniqueIndexScanner;
use Tests\Architecture\Support\TenantOnlyUniqueRatchetChecker;
use Tests\TestCase;

/**
 * Operator-editable catalogue rows are company-owned: a code, SKU, number, or
 * name key that is unique tenant-wide prevents company B from creating the same
 * value company A already owns. This live-schema ratchet freezes those legacy
 * keys until their migrations add company_id.
 *
 * Deliberately excluded as tenant-global/non-catalogue: users,
 * document_sequences, journal_entries, onboarding_checklists,
 * tenant_signing_keys, idempotency keys, pos_customer_aliases, banks, and
 * media_assets.
 */
final class TenantOnlyUniqueOnCatalogueTablesRatchetTest extends TestCase
{
    use RefreshDatabase;

    public const CATALOGUE_TABLES = [
        'products',
        'product_variants',
        'partners',
        'units',
        'unit_categories',
        'payment_methods',
        'payment_repositories',
        'accounts',
        'tax_configurations',
        'categories',
        'brands',
        'product_attributes',
        'vehicles',
        'loyalty_members',
        'pos_terminals',
        'locations',
        'documents',
    ];

    private const BASELINE_RELATIVE = 'tests/Architecture/baselines/tenant-only-unique-baseline.json';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG-only ratchet — gated by the backend-test-pgsql lane');
        }
    }

    #[Test]
    public function live_tenant_only_unique_indexes_match_the_reviewed_baseline(): void
    {
        $liveIndexes = (new TenantOnlyUniqueIndexScanner)->scan(
            DB::connection(),
            self::CATALOGUE_TABLES,
        );
        $baseline = TenantOnlyUniqueBaseline::fromFile(base_path(self::BASELINE_RELATIVE));
        $report = (new TenantOnlyUniqueRatchetChecker)->check($liveIndexes, $baseline);

        self::assertSame([], $report->violations(), $report->message());
    }
}
