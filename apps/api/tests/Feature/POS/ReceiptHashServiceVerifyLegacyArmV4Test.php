<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §6/§17 — `ReceiptHashService::verifyLegacyArm()`'s
 * per-row `sealed_hash_algorithm` dispatch, exercised as a STANDALONE named
 * test file (the manifest names this file explicitly; prior coverage via
 * `BackfillSealedHashAlgorithmCommandTest.php` only exercises the BACKFILL
 * COMMAND's own discriminator-setting correctness, never
 * `verifyLegacyArm()`'s actual re-verification dispatch — a real,
 * previously-untested mechanism).
 *
 * `verifyTerminalChain()` (legacy arm AND fiscal arm) is the public entry
 * point exercised here; every fixture terminal has zero `fiscal_events`
 * rows, so the fiscal arm trivially passes and the assertions isolate the
 * legacy arm's per-row dispatch behavior.
 */
final class ReceiptHashServiceVerifyLegacyArmV4Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private ReceiptHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create();

        $this->hashService = $this->app->make(ReceiptHashService::class);
    }

    public function test_pure_v2_chain_verifies(): void
    {
        $terminal = $this->makeTerminal();

        $previousHash = null;
        foreach ([1, 2, 3] as $sequence) {
            $previousHash = $this->sealReceipt($terminal, SealedHashAlgorithm::LegacyPipeV1, $sequence, $previousHash);
        }

        self::assertTrue($this->hashService->verifyTerminalChain(Terminal::query()->findOrFail($terminal->id)));
    }

    public function test_pure_v3_orphan_chain_verifies(): void
    {
        // "Orphan" — v3-schema-sealed receipts (ReceiptFinalizationService
        // seals BOTH v2 AND v3 without setting fiscal_event_id) that
        // nonetheless land in the "legacy" (fiscal_event_id IS NULL)
        // partition, a real transitional-era shape per §6's docblock.
        $terminal = $this->makeTerminal();

        $previousHash = null;
        foreach ([1, 2, 3] as $sequence) {
            $previousHash = $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, $sequence, $previousHash);
        }

        self::assertTrue($this->hashService->verifyTerminalChain(Terminal::query()->findOrFail($terminal->id)));
    }

    public function test_mixed_v2_then_v3_chain_per_row_dispatch_verifies(): void
    {
        // Proves the dispatch is PER-ROW, not a single terminal-wide
        // assumption -- the second row's algorithm differs from the
        // first's, and the chain still verifies because each row is
        // re-hashed under ITS OWN sealed_hash_algorithm.
        $terminal = $this->makeTerminal();

        $hashAfterFirst = $this->sealReceipt($terminal, SealedHashAlgorithm::LegacyPipeV1, 1, null);
        $hashAfterSecond = $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 2, $hashAfterFirst);
        self::assertNotSame($hashAfterFirst, $hashAfterSecond);

        self::assertTrue($this->hashService->verifyTerminalChain(Terminal::query()->findOrFail($terminal->id)));
    }

    public function test_tamper_under_legacy_pipe_v1_is_detected(): void
    {
        $terminal = $this->makeTerminal();
        // The tampered value must be written DURING the same
        // pending_seal -> fiscalized transition sealReceipt() performs,
        // not as a SEPARATE subsequent UPDATE -- `prevent_receipt_modification()`
        // (PG immutability trigger) permits ANY column value change on
        // that ONE transition, but rejects every fiscal_hash-changing
        // UPDATE afterward (real production tampering can only occur via
        // a trigger bypass outside the ORM entirely, which is exactly the
        // threat class this trigger exists to prevent -- not something a
        // normal ORM UPDATE call can simulate post-seal).
        $this->sealReceipt($terminal, SealedHashAlgorithm::LegacyPipeV1, 1, null, forcedHash: str_repeat('f', 64));

        self::assertFalse($this->hashService->verifyTerminalChain(Terminal::query()->findOrFail($terminal->id)));
    }

    public function test_tamper_under_canonical_json_v3_is_detected(): void
    {
        $terminal = $this->makeTerminal();
        $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 1, null, forcedHash: str_repeat('f', 64));

        self::assertFalse($this->hashService->verifyTerminalChain(Terminal::query()->findOrFail($terminal->id)));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeTerminal(): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            // last_hash stays null throughout this file's fixtures --
            // verifyLegacyArm()'s tail-anchor check only fires when
            // terminal->last_hash is non-null (§17 scope: this file
            // isolates the per-row algorithm dispatch, not the tail-anchor
            // check, which is exercised elsewhere).
            'last_hash' => null,
        ]);
    }

    /**
     * Creates a sealed `pos_receipts` row for the given algorithm/sequence,
     * computes its REAL expected hash via
     * `ReceiptHashService::computeHashForAlgorithm()` (the same dispatch
     * point `verifyLegacyArm()` itself uses), persists it, and returns the
     * hash so the caller can chain the next row's `previous_hash` off it.
     */
    private function sealReceipt(Terminal $terminal, SealedHashAlgorithm $algorithm, int $sequence, ?string $previousHash, ?string $forcedHash = null): string
    {
        // Created PendingSeal, not Fiscalized: `prevent_receipt_modification()`
        // (PG immutability trigger) only permits a subsequent UPDATE that
        // sets fiscal_hash on a `pending_seal -> fiscalized` transition --
        // every other UPDATE branch on an already-`fiscalized` row rejects
        // any fiscal_hash change outright. Creating directly as Fiscalized
        // with a placeholder hash and then overwriting it (the original,
        // SQLite-only-tested shape of this helper) trips that trigger
        // under real PG.
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'receipt_number' => sprintf('T001-C001-L01-POS01-%s-%s-%08d', date('Y'), substr($terminal->id, 0, 8), $sequence),
            'chain_sequence' => $sequence,
            'posted_at' => Carbon::parse('2026-05-20T10:00:00Z')->addMinutes($sequence),
            'previous_hash' => $previousHash,
            'fiscal_hash' => str_repeat('0', 64), // placeholder, overwritten below
            'fiscal_event_id' => null,
            'sealed_hash_algorithm' => $algorithm->value,
            'is_voided' => false,
            'is_training' => false,
            'fiscal_status' => FiscalStatus::PendingSeal,
        ]);

        $expectedHash = $this->hashService->computeHashForAlgorithm($receipt, $algorithm, $previousHash);
        // A caller-forced (tampered) value is written INSTEAD of the real
        // one, but still during this same permitted transition -- see the
        // tamper tests above for why this must happen here, not via a
        // later UPDATE.
        $storedHash = $forcedHash ?? $expectedHash;
        $receipt->fiscal_hash = $storedHash;
        $receipt->fiscal_status = FiscalStatus::Fiscalized;
        $receipt->save();

        return $storedHash;
    }
}
