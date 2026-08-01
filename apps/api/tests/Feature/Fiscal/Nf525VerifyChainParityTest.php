<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\SealedHashAlgorithm;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §6/§17 — `pos:verify-chains` (CLI,
 * `VerifyPosChainCommand` -> `ReceiptHashService::verifyTerminalChain`)
 * versus the live NF525 `POST /api/v1/compliance/nf525/verify-chains`
 * endpoint (`Nf525ExportController::verifyChains` ->
 * `Nf525DataProvider::verifyReceiptChain`) parity, as a STANDALONE named
 * test file.
 *
 * Both verifiers share the SAME per-row `sealed_hash_algorithm` dispatch
 * (`ReceiptHashService::resolveSealedHashAlgorithm()` /
 * `computeHashForAlgorithm()` -- "identical repair", per both methods' own
 * docblocks) and agree on a clean chain and on genuine tampering of a
 * NON-voided row. They deliberately DIVERGE on a voided row's tamper: this
 * file asserts that divergence explicitly rather than silently, per the
 * pre-existing, documented, NOT-fixed-by-this-feature decision recorded at
 * `Nf525DataProvider::verifyReceiptChain()` ("Pre-existing is_voided
 * divergence, noted not fixed") --
 * `ReceiptHashService::verifyLegacyArm()` filters `is_voided=false` on its
 * legacy query; `Nf525DataProvider::verifyReceiptChain()`'s legacy query
 * does NOT.
 */
final class Nf525VerifyChainParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $viewer;

    private ReceiptHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->viewer->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->viewer->givePermissionTo('pos.view_reports');
        $this->viewer->givePermissionTo('compliance.verify_chains');

        $this->hashService = $this->app->make(ReceiptHashService::class);
    }

    public function test_both_verifiers_agree_valid_on_a_clean_chain(): void
    {
        $terminal = $this->makeTerminal();
        $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 1, null, isVoided: false);

        self::assertTrue($this->runPosVerifyChains($terminal));
        self::assertTrue($this->runNf525VerifyChains($terminal));
    }

    public function test_both_verifiers_agree_invalid_when_a_non_voided_row_is_tampered(): void
    {
        $terminal = $this->makeTerminal();
        // The tampered value is written DURING sealReceipt()'s own
        // pending_seal -> fiscalized transition, not via a later UPDATE --
        // `prevent_receipt_modification()` (PG immutability trigger)
        // permits ANY column value change on that one transition but
        // rejects every fiscal_hash-changing UPDATE afterward.
        $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 1, null, isVoided: false, forcedHash: str_repeat('f', 64));

        self::assertFalse($this->runPosVerifyChains($terminal));
        self::assertFalse($this->runNf525VerifyChains($terminal));
    }

    public function test_documented_is_voided_asymmetry_on_a_tampered_voided_row(): void
    {
        // Row 1 anchors the chain (not voided). Row 2 is VOIDED and its
        // fiscal_hash is tampered AT SEAL TIME (see the note in the
        // previous test for why it cannot be a subsequent UPDATE under
        // real PG).
        $terminal = $this->makeTerminal();
        $hashAfterFirst = $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 1, null, isVoided: false);
        $this->sealReceipt($terminal, SealedHashAlgorithm::CanonicalJsonV3, 2, $hashAfterFirst, isVoided: true, forcedHash: str_repeat('f', 64));

        // pos:verify-chains (ReceiptHashService::verifyLegacyArm) excludes
        // is_voided rows from its legacy query entirely -- the tampered
        // voided row is invisible to it, so the (row-1-only) chain still
        // verifies clean.
        self::assertTrue(
            $this->runPosVerifyChains($terminal),
            'pos:verify-chains is documented to exclude is_voided rows and must NOT see the tampered voided row',
        );

        // The NF525 verify-chains endpoint (Nf525DataProvider::verifyReceiptChain)
        // does NOT filter is_voided on its legacy query -- the tampered
        // voided row IS in its walk and its hash mismatch is caught.
        self::assertFalse(
            $this->runNf525VerifyChains($terminal),
            'the NF525 endpoint is documented to include is_voided rows and MUST catch the tampered voided row',
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function runPosVerifyChains(Terminal $terminal): bool
    {
        $result = $this->artisan('pos:verify-chains', ['--terminal' => $terminal->id]);
        self::assertInstanceOf(PendingCommand::class, $result);
        $exitCode = $result->run();

        return $exitCode === 0;
    }

    private function runNf525VerifyChains(Terminal $terminal): bool
    {
        Sanctum::actingAs($this->viewer);
        $response = $this->postJson('/api/v1/compliance/nf525/verify-chains');
        $response->assertOk();

        /** @var list<array<string, mixed>> $results */
        $results = $response->json('data.terminals');
        $row = collect($results)->firstWhere('terminal_id', $terminal->id);
        self::assertIsArray($row, 'terminal not present in NF525 verify-chains response');

        return (bool) $row['receipt_chain']['is_valid'];
    }

    private function makeTerminal(): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'last_hash' => null,
        ]);
    }

    private function sealReceipt(Terminal $terminal, SealedHashAlgorithm $algorithm, int $sequence, ?string $previousHash, bool $isVoided, ?string $forcedHash = null): string
    {
        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Created PendingSeal, not Fiscalized: `prevent_receipt_modification()`
        // (PG immutability trigger) only permits a subsequent UPDATE that
        // sets fiscal_hash on a `pending_seal -> fiscalized` transition --
        // every other UPDATE branch on an already-`fiscalized` row rejects
        // any fiscal_hash change outright.
        $receipt = Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'receipt_number' => sprintf('T001-C001-L01-POS01-%s-%s-%08d', date('Y'), substr($terminal->id, 0, 8), $sequence),
            'chain_sequence' => $sequence,
            'posted_at' => Carbon::parse('2026-05-20T10:00:00Z')->addMinutes($sequence),
            'previous_hash' => $previousHash,
            'fiscal_hash' => str_repeat('0', 64), // placeholder, overwritten below
            'fiscal_event_id' => null,
            'sealed_hash_algorithm' => $algorithm->value,
            'is_voided' => $isVoided,
            // pos_receipts_void_logic CHECK: is_voided=true requires BOTH
            // voided_at and voided_by to be non-null.
            'voided_at' => $isVoided ? Carbon::parse('2026-05-20T10:05:00Z') : null,
            'voided_by' => $isVoided ? $cashier->id : null,
            'is_training' => false,
            'fiscal_status' => FiscalStatus::PendingSeal,
        ]);

        $expectedHash = $this->hashService->computeHashForAlgorithm($receipt, $algorithm, $previousHash);
        // A caller-forced (tampered) value is written INSTEAD of the real
        // one, but still during this same permitted transition.
        $storedHash = $forcedHash ?? $expectedHash;
        $receipt->fiscal_hash = $storedHash;
        $receipt->fiscal_status = FiscalStatus::Fiscalized;
        $receipt->save();

        return $storedHash;
    }
}
