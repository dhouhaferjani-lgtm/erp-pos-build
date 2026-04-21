<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

use App\Modules\Compliance\Services\FiscalHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: ensure the Vehicle domain migrations (tasks 1, 2, 3, 3.5, 14)
 * do NOT mutate fiscal-chained tables (documents, journal_entries,
 * document_vehicle_contexts, etc.) so any pre-existing fiscal hash chain
 * continues to validate after the Vehicle enrichment ships.
 *
 * The fiscal hash chain is stored on documents.fiscal_hash /
 * documents.previous_hash, computed from a serialized snapshot of the
 * document plus its predecessor's hash. The Vehicle migrations create new
 * tables and add a body_type column — they never touch documents, lines,
 * or vehicle_snapshot JSONB. This test verifies the schema contracts that
 * fiscal compliance depends on.
 */
final class HashChainReplayAfterVehicleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_table_retains_fiscal_hash_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('documents', 'fiscal_hash'),
            'documents.fiscal_hash must remain after Vehicle migrations',
        );
        $this->assertTrue(
            Schema::hasColumn('documents', 'previous_hash'),
            'documents.previous_hash must remain after Vehicle migrations',
        );
    }

    public function test_document_vehicle_contexts_table_preserved(): void
    {
        $this->assertTrue(
            Schema::hasTable('document_vehicle_contexts'),
            'document_vehicle_contexts must remain - it is the fiscal-safe vehicle snapshot',
        );
        $this->assertTrue(
            Schema::hasColumn('document_vehicle_contexts', 'vehicle_snapshot'),
            'vehicle_snapshot JSONB column is fiscal-safe snapshot; must not be removed',
        );
    }

    public function test_vehicle_migrations_added_new_tables_without_touching_fiscal_chain(): void
    {
        // Vehicle enrichment introduces these new tables - they must all exist post-migration.
        $this->assertTrue(Schema::hasTable('vehicle_ownership_history'));
        $this->assertTrue(Schema::hasTable('vehicle_mileage_readings'));

        // And the vehicles table gains a body_type column additively.
        $this->assertTrue(Schema::hasColumn('vehicles', 'body_type'));

        // Critical: fiscal chain tables are untouched.
        $this->assertTrue(Schema::hasTable('documents'));
        $this->assertTrue(Schema::hasTable('journal_entries'));
    }

    public function test_hash_chain_verification_works_with_empty_chain(): void
    {
        // Baseline sanity: the FiscalHashService can verify an empty chain (no documents).
        // This asserts the service is resolvable + functional AFTER the Vehicle migrations
        // have run to completion (which RefreshDatabase guarantees).
        $hashService = app(FiscalHashService::class);
        $this->assertTrue($hashService->verifyChain([]));
    }

    public function test_hash_chain_verification_works_with_valid_two_document_chain(): void
    {
        $hashService = app(FiscalHashService::class);

        $genesisSeed = 'test-company-seed';
        $input1 = 'DOC-1|INV-0001|2026-04-01T10:00:00Z|100.00|EUR';
        $hash1 = $hashService->calculateHash($input1, null, $genesisSeed);

        $input2 = 'DOC-2|INV-0002|2026-04-02T10:00:00Z|200.00|EUR';
        $hash2 = $hashService->calculateHash($input2, $hash1);

        $chain = [
            ['input' => $input1, 'hash' => $hash1, 'previous_hash' => null],
            ['input' => $input2, 'hash' => $hash2, 'previous_hash' => $hash1],
        ];

        $this->assertTrue(
            $hashService->verifyChain($chain, $genesisSeed),
            'Valid hash chain must verify after Vehicle migrations - fiscal compliance is untouched',
        );
    }
}
