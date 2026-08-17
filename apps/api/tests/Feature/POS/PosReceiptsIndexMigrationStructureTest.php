<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PosReceiptsIndexMigrationStructureTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_NAMES = [
        'pos_receipts_company_location_posted_at_idx',
        'pos_receipts_company_posted_at_production_idx',
    ];

    public function test_postgres_reporting_indexes_are_structural_idempotent_and_reversible(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The pg_indexes contract requires PostgreSQL.');
        }

        $migration = require database_path('migrations/tenant/2026_08_17_100000_add_receipt_reporting_indexes.php');
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->up();
        $migration->up();

        $definitions = $this->indexDefinitions();
        $this->assertSame(self::INDEX_NAMES, $definitions->keys()->sort()->values()->all());
        $this->assertStringContainsString(
            'USING btree (company_id, location_id, posted_at DESC)',
            $definitions['pos_receipts_company_location_posted_at_idx'],
        );
        $this->assertStringContainsString(
            'USING btree (company_id, posted_at DESC)',
            $definitions['pos_receipts_company_posted_at_production_idx'],
        );
        $this->assertStringContainsString(
            'WHERE (training_flag = false)',
            $definitions['pos_receipts_company_posted_at_production_idx'],
        );

        $migration->down();
        $this->assertSame([], $this->indexDefinitions()->all());

        $migration->up();
    }

    /**
     * @return Collection<string, string>
     */
    private function indexDefinitions(): Collection
    {
        return collect(DB::select(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname IN (?, ?)',
            self::INDEX_NAMES,
        ))->mapWithKeys(static fn (object $row): array => [
            (string) $row->indexname => (string) $row->indexdef,
        ]);
    }
}
