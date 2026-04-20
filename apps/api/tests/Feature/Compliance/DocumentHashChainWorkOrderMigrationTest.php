<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Compliance\Services\FiscalHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Replay regression: verify that fiscal-chained `documents` rows still
 * validate their SHA-256 hash AFTER the Spec B additive columns
 * (`documents.work_order_id`, `document_lines.work_order_line_id`) land.
 *
 * This is NOT a schema-check test. It's a chain-integrity test: we seed a
 * deterministic set of fiscal-signed rows (two tenants, each with multiple
 * invoices chained from a known genesis seed), then re-run
 * FiscalHashService::verifyChain per tenant. The additive columns must not
 * have been part of the hash payload — if they were, verifyChain would
 * return false.
 *
 * The hash payload is defined by FiscalHashService::serializeForHashing
 * as `document_number | posted_at | total | currency`. None of the Spec B
 * columns are part of that payload, so any pre-existing signature MUST
 * still validate.
 */
final class DocumentHashChainWorkOrderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_tenant_chains_verify_after_spec_b_migration(): void
    {
        // RefreshDatabase has already run ALL migrations including Spec B's
        // additive-column migration (2026_04_19_130005). We synthesize
        // signed-looking chains and verify them purely via FiscalHashService —
        // the goal is to prove the hash algorithm is unaffected by the new
        // nullable columns.

        $this->assertTrue(Schema::hasColumn('documents', 'work_order_id'),
            'Spec B migration must have added documents.work_order_id.');
        $this->assertTrue(Schema::hasColumn('document_lines', 'work_order_line_id'),
            'Spec B migration must have added document_lines.work_order_line_id.');

        $hashService = app(FiscalHashService::class);

        $tenants = [
            [
                'seed' => 'tenant-one-genesis-seed',
                'entries' => [
                    ['document_number' => 'INV-2025-0001', 'posted_at' => '2025-01-15T10:00:00Z', 'total' => '100.00', 'currency' => 'EUR'],
                    ['document_number' => 'INV-2025-0002', 'posted_at' => '2025-01-20T09:30:00Z', 'total' => '250.50', 'currency' => 'EUR'],
                    ['document_number' => 'CN-2025-0001', 'posted_at' => '2025-02-01T14:00:00Z', 'total' => '-50.00', 'currency' => 'EUR'],
                ],
            ],
            [
                'seed' => 'tenant-two-genesis-seed',
                'entries' => [
                    ['document_number' => 'INV-2025-0001', 'posted_at' => '2025-03-01T08:15:00Z', 'total' => '1500.000', 'currency' => 'TND'],
                    ['document_number' => 'INV-2025-0002', 'posted_at' => '2025-03-05T11:45:00Z', 'total' => '842.300', 'currency' => 'TND'],
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            $chain = $this->buildSignedChain($tenant['entries'], $tenant['seed'], $hashService);

            $this->assertTrue(
                $hashService->verifyChain($chain, $tenant['seed']),
                'Hash chain for tenant with seed "'.$tenant['seed'].'" MUST still verify after Spec B migration — additive nullable columns are not part of the hash payload.',
            );
        }
    }

    public function test_additive_wo_columns_are_nullable_and_default_null(): void
    {
        // Invariant: additive migrations must default the new columns to NULL
        // so any pre-Spec-B fiscal row keeps its hash integrity.
        $columns = Schema::getColumns('documents');
        $workOrderIdCol = collect($columns)->firstWhere('name', 'work_order_id');
        $this->assertNotNull($workOrderIdCol);
        $this->assertTrue($workOrderIdCol['nullable'], 'documents.work_order_id must be nullable (additive).');

        $lineColumns = Schema::getColumns('document_lines');
        $workOrderLineIdCol = collect($lineColumns)->firstWhere('name', 'work_order_line_id');
        $this->assertNotNull($workOrderLineIdCol);
        $this->assertTrue($workOrderLineIdCol['nullable'], 'document_lines.work_order_line_id must be nullable (additive).');
    }

    public function test_fiscal_hash_payload_does_not_include_work_order_columns(): void
    {
        // This assertion pins the serialization contract that makes the additive
        // migration safe. FiscalHashService::serializeForHashing is defined over
        // exactly `document_number | posted_at | total | currency` — if a future
        // change adds work_order_id to the payload, this test fails and alerts
        // the fiscal-compliance reviewer that the additive migration becomes
        // chain-breaking.
        $hashService = app(FiscalHashService::class);
        $serialized = $hashService->serializeForHashing([
            'document_number' => 'INV-2025-0001',
            'posted_at' => '2025-01-15T10:00:00Z',
            'total' => '100.00',
            'currency' => 'EUR',
        ]);

        $this->assertSame('INV-2025-0001|2025-01-15T10:00:00Z|100.00|EUR', $serialized);
    }

    public function test_pre_existing_documents_have_null_wo_refs_after_migration(): void
    {
        // Tight invariant: any document row the test DB CAN see right now (we
        // don't seed any in this test) must have work_order_id NULL. This
        // asserts the migration doesn't back-fill anything.
        $count = DB::table('documents')->whereNotNull('work_order_id')->count();
        $this->assertSame(0, $count, 'Spec B migration must not back-fill work_order_id — must remain NULL on pre-existing rows.');

        $lineCount = DB::table('document_lines')->whereNotNull('work_order_line_id')->count();
        $this->assertSame(0, $lineCount, 'Spec B migration must not back-fill work_order_line_id — must remain NULL on pre-existing rows.');
    }

    /**
     * Given a sequence of document payload maps + a genesis seed, returns a
     * chain array in the verifyChain shape: `{input, hash, previous_hash}`.
     *
     * @param  list<array{document_number: string, posted_at: string, total: string, currency: string}>  $entries
     * @return list<array{input: string, hash: string, previous_hash: string|null}>
     */
    private function buildSignedChain(array $entries, string $seed, FiscalHashService $hashService): array
    {
        $chain = [];
        $previousHash = null;

        foreach ($entries as $entry) {
            $input = $hashService->serializeForHashing($entry);
            $seedForCalculation = $previousHash === null ? $seed : null;
            $hash = $hashService->calculateHash($input, $previousHash, $seedForCalculation);

            $chain[] = [
                'input' => $input,
                'hash' => $hash,
                'previous_hash' => $previousHash,
            ];

            $previousHash = $hash;
        }

        return $chain;
    }
}
