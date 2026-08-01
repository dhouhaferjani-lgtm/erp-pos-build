<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\SealedHashAlgorithm;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §6 — `fiscal:backfill-sealed-hash-algorithm`.
 *
 * Seeds two legacy (`fiscal_event_id IS NULL`) receipts (one v2/legacy_pipe_v1,
 * one v3/canonical_json_v3), each already `sealed_hash_algorithm = NULL`
 * from its first pending_seal->fiscalized transition, and asserts the
 * command correctly re-derives each row's actual algorithm (via a REAL
 * hash computed through {@see ReceiptHashService::computeHashForAlgorithm()})
 * and stamps the terminal's backfill-completion timestamp.
 *
 * **PG-safe fixture (fiscal re-verification item 4).** `sealAndForgetAlgorithm()`
 * seals its receipt with `sealed_hash_algorithm` NULL from the row's very
 * first `pending_seal -> fiscalized` transition — mirroring exactly how a
 * genuine pre-migration row got there (never had a value to begin with) —
 * rather than the earlier SQLite-only shape (seal normally via
 * `ReceiptFinalizationService::finalize()`, then a raw value->NULL UPDATE
 * to simulate "predates the column"). Under real PG, §6.2's
 * `prevent_receipt_modification()` trigger permits ONLY the one-time
 * NULL->value direction (`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`,
 * guarded by `OLD.sealed_hash_algorithm IS NULL`); a value->NULL rewrite on
 * an already-fiscalized row is REJECTED outright — this fixture never
 * attempts one. The hash itself is REAL, computed through the same
 * `ReceiptHashService::computeHashForAlgorithm()` dispatch point the
 * command under test uses, so the discrimination logic is genuinely
 * exercised, not faked. Runs under BOTH the default (SQLite) and
 * `phpunit-pgsql.xml` configs.
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

    // =================================================================
    // review round-2 IMPORTANT 13(a) — chain off each row's OWN stored
    // previous_hash, not a running variable reconstructed across an
    // is_voided/is_training-UNFILTERED walk.
    // =================================================================

    public function test_a_voided_row_interspersed_does_not_break_discrimination_of_the_row_after_it(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        // Three receipts sealed SEQUENTIALLY (via `sealAndForgetAlgorithm()`,
        // which advances the terminal's real hash-chain state) so each
        // one's previous_hash is the genuine prior fiscal_hash -- the
        // middle one is VOIDED, which
        // ReceiptHashService::verifyLegacyArm()'s own query excludes
        // entirely. A running-variable reconstruction of "the previous
        // hash" that walked an unfiltered query would skip past the void
        // and derive the WRONG previous_hash for receipt 3; reading each
        // row's own stored column sidesteps this because receipt 3's
        // previous_hash was set to receipt 2's actual fiscal_hash (the
        // true chain includes every sealed row, voided or not -- only the
        // VERIFIER's query filters). Each is already sealed_hash_algorithm
        // NULL from its first transition -- no later batch value->NULL
        // reset needed (real PG rejects that; see class docblock).
        $receipt1 = $this->sealAndForgetAlgorithm($terminal, 'T001-C001-L01-POS01-2026-00000401');
        $receipt2 = $this->sealAndForgetAlgorithm($terminal, 'T001-C001-L01-POS01-2026-00000402');
        $this->voidReceipt($receipt2);
        $receipt3 = $this->sealAndForgetAlgorithm($terminal, 'T001-C001-L01-POS01-2026-00000403');

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:backfill-sealed-hash-algorithm', [
            '--tenant' => $this->tenant->id,
        ]);
        self::assertSame(0, $exitCode);

        self::assertSame('legacy_pipe_v1', $this->freshSealedHashAlgorithm($receipt1));
        self::assertSame('legacy_pipe_v1', $this->freshSealedHashAlgorithm($receipt2));
        self::assertSame(
            'legacy_pipe_v1',
            $this->freshSealedHashAlgorithm($receipt3),
            'receipt 3 must still discriminate correctly despite the voided row between it and receipt 1',
        );

        $freshTerminal = $terminal->fresh();
        self::assertNotNull($freshTerminal);
        self::assertNotNull($freshTerminal->sealed_hash_algorithm_backfill_completed_at);
    }

    // =================================================================
    // review round-2 IMPORTANT 13(b) — withhold the completion stamp
    // when any row was skipped, unless --force.
    // =================================================================

    public function test_completion_stamp_is_withheld_when_a_row_is_skipped_and_written_with_force(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        // Force a fiscal_hash NEITHER candidate algorithm can match -- a
        // genuine skip. Written during the receipt's own pending_seal ->
        // fiscalized transition (forcedHash), not a later UPDATE: real PG
        // never permits rewriting fiscal_hash on an already-fiscalized row
        // (it is immutable even under the sealed_hash_algorithm backfill
        // branch, see sealAndForgetAlgorithm()'s docblock).
        $receipt = $this->sealAndForgetAlgorithm($terminal, 'T001-C001-L01-POS01-2026-00000501', forcedHash: str_repeat('f', 64));

        $this->withoutMockingConsoleOutput();
        $this->artisan('fiscal:backfill-sealed-hash-algorithm', [
            '--tenant' => $this->tenant->id,
        ]);

        self::assertNull($this->freshSealedHashAlgorithm($receipt), 'a genuinely skipped row must stay null');
        $freshTerminal = $terminal->fresh();
        self::assertNotNull($freshTerminal);
        self::assertNull(
            $freshTerminal->sealed_hash_algorithm_backfill_completed_at,
            'completion must be WITHHELD when a row was skipped',
        );

        // Re-run with --force -- the terminal is now stamped despite the
        // still-unresolved row.
        $this->artisan('fiscal:backfill-sealed-hash-algorithm', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
        ]);

        $freshTerminalAfterForce = $terminal->fresh();
        self::assertNotNull($freshTerminalAfterForce);
        self::assertNotNull(
            $freshTerminalAfterForce->sealed_hash_algorithm_backfill_completed_at,
            '--force must stamp completion despite the still-unresolved skipped row',
        );
    }

    /**
     * Seals a receipt with `sealed_hash_algorithm` NULL from its very
     * FIRST `pending_seal -> fiscalized` transition — exactly how a
     * genuine pre-migration row got there (never had a value to begin
     * with) — so the fixture needs no later value->NULL rewrite (which
     * real PG's §6.2 trigger branch rejects; see class docblock). The
     * hash is REAL: computed via the same
     * `ReceiptHashService::computeHashForAlgorithm()` dispatch point the
     * command under test uses, mirroring `ReceiptFinalizationService::finalize()`'s
     * own schema-version dispatch (2 -> LegacyPipeV1, 3 -> CanonicalJsonV3).
     */
    private function sealAndForgetAlgorithm(Terminal $terminal, string $receiptNumber, ?string $forcedHash = null): Receipt
    {
        $algorithm = match ($terminal->fiscal_schema_version) {
            2 => SealedHashAlgorithm::LegacyPipeV1,
            3 => SealedHashAlgorithm::CanonicalJsonV3,
            default => throw new \LogicException("Unsupported fiscal_schema_version: {$terminal->fiscal_schema_version}"),
        };

        /** @var Receipt $receipt */
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'currency' => 'EUR',
            'previous_hash' => $terminal->last_hash,
            'chain_sequence' => $terminal->current_sequence,
            'fiscal_hash' => str_repeat('0', 64), // placeholder, overwritten below in the same permitted transition
            'fiscal_status' => FiscalStatus::PendingSeal,
            'sealed_hash_algorithm' => null,
        ]);

        $realHash = app(ReceiptHashService::class)->computeHashForAlgorithm($receipt, $algorithm, $terminal->last_hash);

        // A caller-forced (tampered) value is written INSTEAD of the real
        // one, but still during this same permitted transition --
        // `fiscal_hash` is immutable on an already-fiscalized row (not
        // even the sealed_hash_algorithm backfill branch permits changing
        // it: `NEW.fiscal_hash IS NOT DISTINCT FROM OLD.fiscal_hash` is
        // part of its guard), so a genuine "neither candidate matches"
        // fixture can only be constructed by writing the wrong value HERE,
        // never via a later UPDATE. Mirrors
        // ReceiptHashServiceVerifyLegacyArmV4Test::sealReceipt()'s own
        // forcedHash parameter.
        $storedHash = $forcedHash ?? $realHash;

        // ONE UPDATE, the pending_seal -> fiscalized transition —
        // sealed_hash_algorithm is never assigned here, so it stays NULL
        // through this permitted write.
        $receipt->fiscal_hash = $storedHash;
        $receipt->fiscal_status = FiscalStatus::Fiscalized;
        $receipt->save();

        // The terminal's own hash-chain state advances off the REAL hash,
        // never the forced/tampered one -- mirrors the original design:
        // only THIS row's own stored `fiscal_hash` is wrong, the chain
        // itself (and any subsequent row's `previous_hash`) stays genuine.
        $terminal->last_hash = $realHash;
        $terminal->current_sequence++;
        $terminal->save();

        return $receipt->refresh();
    }

    /**
     * PG-safe void: `prevent_receipt_modification()`'s only permitted
     * `fiscalized -> voided` branch requires `fiscal_status` to actually
     * flip to `voided` in the SAME update as `is_voided` — setting
     * `is_voided` alone (this test's original SQLite-only shape) leaves
     * `fiscal_status` unchanged and is REJECTED under real PG as an
     * unrecognized fiscalized-row UPDATE. `voided_at`/`voided_by` must be
     * non-null together per the `pos_receipts_void_logic` CHECK
     * constraint.
     */
    private function voidReceipt(Receipt $receipt): void
    {
        $voidingUser = User::factory()->create(['tenant_id' => $this->tenant->id]);

        DB::table('pos_receipts')->where('id', $receipt->id)->update([
            'is_voided' => true,
            'fiscal_status' => FiscalStatus::Voided->value,
            'voided_at' => now(),
            'voided_by' => $voidingUser->id,
        ]);
    }

    private function freshSealedHashAlgorithm(Receipt $receipt): ?string
    {
        $fresh = $receipt->fresh();
        self::assertNotNull($fresh);

        return $fresh->sealed_hash_algorithm;
    }
}
