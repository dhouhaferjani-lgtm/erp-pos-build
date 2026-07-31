<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §6 — `fiscal:backfill-sealed-hash-algorithm`.
 *
 * Seeds two legacy (`fiscal_event_id IS NULL`) receipts sealed via the REAL
 * `ReceiptFinalizationService` (one v2/legacy_pipe_v1, one v3/canonical_json_v3),
 * then simulates the pre-migration state (`sealed_hash_algorithm = NULL`)
 * via a raw UPDATE and asserts the command correctly re-derives each row's
 * actual algorithm and stamps the terminal's backfill-completion timestamp.
 *
 * **Default (SQLite) config ONLY — do not run under phpunit-pgsql.xml.**
 * The value->NULL raw UPDATE this fixture uses to simulate "predates the
 * column" is itself only possible because SQLite has no
 * `prevent_receipt_modification()` trigger. Under real PG, §6.2's trigger
 * permits ONLY the one-time NULL->value direction (by design — a genuine
 * pre-migration row is NULL from its very first INSERT, never transitions
 * FROM a value), so this fixture's artificial reset is correctly REJECTED
 * by PG and would need row-level trigger bypass to construct. That
 * constraint is a property of the fixture, not of the production code
 * path: the backfill command's own NULL->value write is the
 * trigger-permitted direction and needs no special-casing here. The
 * command's DB-driver-agnostic discrimination logic (bcmath/hash
 * comparison) is fully exercised under SQLite.
 */
final class BackfillSealedHashAlgorithmCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_backfill_correctly_discriminates_legacy_and_v3_sealed_receipts(): void
    {
        $v2Terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);
        $v3Terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 3,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $legacyReceipt = $this->sealAndForgetAlgorithm($v2Terminal, 'T001-C001-L01-POS01-2026-00000101');
        $v3Receipt = $this->sealAndForgetAlgorithm($v3Terminal, 'T001-C001-L01-POS01-2026-00000102');

        self::assertNull($this->freshSealedHashAlgorithm($legacyReceipt));
        self::assertNull($this->freshSealedHashAlgorithm($v3Receipt));

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:backfill-sealed-hash-algorithm', [
            '--tenant' => $this->tenant->id,
        ]);
        self::assertSame(0, $exitCode);

        self::assertSame('legacy_pipe_v1', $this->freshSealedHashAlgorithm($legacyReceipt));
        self::assertSame('canonical_json_v3', $this->freshSealedHashAlgorithm($v3Receipt));

        $freshV2Terminal = $v2Terminal->fresh();
        $freshV3Terminal = $v3Terminal->fresh();
        self::assertNotNull($freshV2Terminal);
        self::assertNotNull($freshV3Terminal);
        self::assertNotNull($freshV2Terminal->sealed_hash_algorithm_backfill_completed_at);
        self::assertNotNull($freshV3Terminal->sealed_hash_algorithm_backfill_completed_at);
    }

    public function test_backfill_is_idempotent_and_never_rewrites_an_already_set_discriminator(): void
    {
        $v2Terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);
        $legacyReceipt = $this->sealAndForgetAlgorithm($v2Terminal, 'T001-C001-L01-POS01-2026-00000201');

        $this->withoutMockingConsoleOutput();
        $this->artisan('fiscal:backfill-sealed-hash-algorithm', ['--tenant' => $this->tenant->id]);
        self::assertSame('legacy_pipe_v1', $this->freshSealedHashAlgorithm($legacyReceipt));

        // Second run must be a no-op — the one-time trigger transition would
        // reject a rewrite attempt at the DB layer regardless, but the
        // command's own `sealed_hash_algorithm IS NULL` guard means it never
        // even attempts one.
        $exitCode = $this->artisan('fiscal:backfill-sealed-hash-algorithm', ['--tenant' => $this->tenant->id]);
        self::assertSame(0, $exitCode);
        self::assertSame('legacy_pipe_v1', $this->freshSealedHashAlgorithm($legacyReceipt));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $v2Terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);
        $legacyReceipt = $this->sealAndForgetAlgorithm($v2Terminal, 'T001-C001-L01-POS01-2026-00000301');

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:backfill-sealed-hash-algorithm', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ]);
        self::assertSame(0, $exitCode);

        self::assertNull($this->freshSealedHashAlgorithm($legacyReceipt));
        $freshTerminal = $v2Terminal->fresh();
        self::assertNotNull($freshTerminal);
        self::assertNull($freshTerminal->sealed_hash_algorithm_backfill_completed_at);
    }

    /**
     * Seals a receipt through the REAL finalize() path (so its fiscal_hash
     * is a genuine algorithm-specific hash, not a hand-computed fixture),
     * then forgets the discriminator to simulate the pre-migration state —
     * every row that predates the `sealed_hash_algorithm` column has NULL
     * by construction, never by an explicit write this test needs to fake.
     */
    private function sealAndForgetAlgorithm(Terminal $terminal, string $receiptNumber): Receipt
    {
        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'currency' => 'EUR',
            'previous_hash' => null,
        ]);

        $sealed = app(ReceiptFinalizationService::class)->finalize($receipt);
        self::assertNotNull($sealed->sealed_hash_algorithm);

        // Direct UPDATE — bypasses Eloquent so the immutability trigger's
        // pre-existing branches (which never permit rewriting a
        // non-fiscal-content column back to NULL) don't reject this
        // test-only simulation of "this row predates the column".
        DB::table('pos_receipts')->where('id', $sealed->id)->update(['sealed_hash_algorithm' => null]);

        return $sealed;
    }

    private function freshSealedHashAlgorithm(Receipt $receipt): ?string
    {
        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);

        return $fresh->sealed_hash_algorithm;
    }
}
