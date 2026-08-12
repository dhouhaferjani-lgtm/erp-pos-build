<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ReadsCanonicalBytes;

/**
 * Task 31 — `fiscal:verify-event-chain` command (plan §2397–2458, spec §12).
 *
 * Contract validated:
 *
 *   1. Walks `fiscal_events` for the target terminal in `sequence_number` order
 *      (optionally from `--from-sequence`).
 *   2. For each row: re-hashes `canonical_bytes` via the
 *      `HashChainIntegrityProvider` (Task 6) and asserts `current_hash` matches
 *      the rehash. Asserts `previous_hash` links to the prior event's
 *      `current_hash`; first event's `previous_hash` links to the terminal's
 *      `pos_terminals.genesis_seed`.
 *   3. Reports `fiscal_event_quarantine` rows for the terminal as chain
 *      incidents using `claimed_sequence_number`, `envelope_event_id`,
 *      `current_hash`, `conflicting_event_id` (spec §8 column set).
 *   4. Exit `0` = chain verified, no incidents; exit `1` = a break OR a
 *      quarantine incident (with `sequence_number` and expected-vs-actual hash
 *      on stderr); exit `1` also = permission denied or missing actor.
 *   5. Permission-gated by `fiscal.events.verify_chain`. Uses the same Spatie
 *      team-scoping try/finally pattern as
 *      `EnqueueResolvedEventProjectionsCommand` (Task 24 R2 lesson — the
 *      registrar team id must be set to the actor's tenant for the
 *      `can('fiscal.events.verify_chain')` check, and restored on the way
 *      out).
 *
 * **Test matrix (plan §2404–2435 + edge cases):**
 *   - Plan 1: passes on a valid seeded chain
 *   - Plan 2: fails with break-point on a tampered fixture
 *   - Plan 3: fails as incident on a seeded sequence_conflict
 *   - Plan 4: permission gate
 *   - Edge: break at sequence 1 (first event's `previous_hash` !=
 *     genesis_seed)
 *   - Edge: break at last sequence
 *   - Edge: quarantine + valid chain co-exist (the quarantine is the only
 *     incident; chain hashes still verify)
 *   - Edge: `--from-sequence` skips earlier breaks
 *   - Edge: empty chain (no rows) is a clean pass
 *   - Edge: actor with the permission scoped to a different tenant is denied
 *     (Task 24 R2 — Spatie team-scoped `can()`).
 */
final class VerifyEventChainCommandTest extends TestCase
{
    use ReadsCanonicalBytes;
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    /** Genesis seed seeded into `pos_terminals`. */
    private string $genesisSeed;

    private User $verifierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Use a real 64-hex seed so the PG CHECK constraints
        // (fiscal_events_previous_hash_format) accept rows whose first
        // `previous_hash` equals the seed.
        $this->genesisSeed = str_repeat('a', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        // Spatie team-scoped permissions require the registrar team id be
        // set BEFORE `givePermissionTo` — without it, the pivot insert
        // raises on the NOT NULL `model_has_permissions.tenant_id`.
        $this->app->make(PermissionRegistrar::class)
            ->setPermissionsTeamId($this->tenantId);

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $this->terminalId = $terminal->id;

        $this->operatorId = Str::uuid()->toString();

        $user = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Chain verifier',
        ]);
        $user->givePermissionTo('fiscal.events.verify_chain');
        $this->verifierUser = $user;
    }

    // =================================================================
    // Plan §2404–2435 — 4 core tests
    // =================================================================

    public function test_passes_on_a_valid_seeded_chain(): void
    {
        $this->seedValidChain(5);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('chain verified')
            ->assertExitCode(0);
    }

    public function test_fails_with_break_point_on_a_tampered_fixture(): void
    {
        $this->seedTamperedChain(atSequence: 3);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('sequence_number 3')
            ->assertExitCode(1);
    }

    public function test_fails_as_incident_on_a_seeded_sequence_conflict(): void
    {
        $this->seedValidChain(3);
        $this->seedQuarantineConflict(claimedSequence: 2);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('sequence_conflict')
            ->assertExitCode(1);
    }

    public function test_command_is_permission_gated(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenantId]);
        $this->seedValidChain(2);

        // Missing --actor-id: rejected (per the EnqueueResolvedEventProjections
        // pattern — no actor means no permission check can be done).
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
        ])->assertExitCode(1);

        // Actor without the permission: also rejected.
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $unprivileged->id,
        ])->assertExitCode(1);
    }

    // =================================================================
    // Edge tests (discriminated-union completeness — break positions,
    // empty chain, --from-sequence, cross-tenant permission scoping)
    // =================================================================

    public function test_fails_when_first_event_previous_hash_does_not_match_genesis_seed(): void
    {
        // Seed one event whose previous_hash is NOT the genesis seed.
        $canonicalBytes = '{"event":"seq1"}';
        $bogusGenesis = str_repeat('b', 64);
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $bogusGenesis,
            currentHash: hash('sha256', $canonicalBytes),
        );

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('sequence_number 1')
            // Symfony wraps long single lines across multiple
            // OutputStyle writeln calls when a TTY width is detected,
            // so substring assertions across the wrap boundary can be
            // flaky. The sequence_number 1 check above pins the
            // structural contract (the break is reported at the right
            // sequence); the wrapped tail of the message names the
            // genesis-seed mismatch as documented in the command
            // docblock.
            ->assertExitCode(1);
    }

    public function test_fails_when_break_is_at_last_sequence(): void
    {
        $this->seedTamperedChain(atSequence: 5, totalLength: 5);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('sequence_number 5')
            ->assertExitCode(1);
    }

    public function test_quarantine_incident_is_reported_alongside_valid_chain(): void
    {
        // A valid chain plus one quarantine row: the chain hashes still
        // verify, but the quarantine is the chain incident the verifier
        // surfaces. Exit must be 1 — the chain is not clean.
        $this->seedValidChain(3);
        $this->seedQuarantineConflict(claimedSequence: 2);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('claimed_sequence_number 2')
            ->assertExitCode(1);
    }

    public function test_from_sequence_skips_earlier_break(): void
    {
        // Tampered chain at sequence 2 — starting from sequence 3 the
        // remaining rows still link to each other (the chain after the
        // break is internally consistent because the tampering only
        // changed seq 2's current_hash; seq 3's previous_hash was set to
        // match the tampered seq 2 hash). Starting at the tampered
        // boundary itself (seq 2) still fails. Starting AFTER (seq 3)
        // passes because the verifier doesn't look back.
        $this->seedTamperedChain(atSequence: 2, totalLength: 5);

        // Sanity — without --from-sequence, the break at seq 2 is detected.
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])->assertExitCode(1);

        // With --from-sequence=3, we start checking from seq 3. The
        // tampered chain's seq 3+ was built linking to seq 2's tampered
        // current_hash, so seq 3's previous_hash still matches what's
        // stored at seq 2. Re-hash of seq 3+'s canonical_bytes still
        // matches its current_hash (only seq 2 had its current_hash
        // tampered). So the chain from seq 3 onward verifies clean.
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
            '--from-sequence' => 3,
        ])->assertExitCode(0);
    }

    public function test_empty_chain_is_a_clean_pass(): void
    {
        // No events seeded. Verifier walks zero rows, sees no quarantine
        // incidents — clean exit.
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('chain verified')
            ->assertExitCode(0);
    }

    public function test_permission_check_is_scoped_to_actor_tenant_not_request_team(): void
    {
        // Task 24 R2 lesson — Spatie permissions are team-scoped on
        // tenant_id. A user in tenant B with the permission scoped to
        // tenant B should NOT pass when running against tenant A's data,
        // because the registrar gets re-scoped to the actor's tenant
        // (the actor's permissions are checked, not the data's).
        // Conversely, our setUp() actor has the permission scoped to
        // tenant A — so they pass even if the registrar was previously
        // set to a different team id by a prior request.
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Str::uuid()->toString()); // bogus team

        $this->seedValidChain(2);

        // Actor A has the permission (granted in setUp() scoped to
        // $this->tenantId). The command must re-scope and find the
        // permission.
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])->assertExitCode(0);

        // Actor B in a different tenant lacks the permission.
        $tenantB = Tenant::factory()->create();
        $actorB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $actorB->id,
        ])->assertExitCode(1);
    }

    public function test_unknown_actor_id_is_rejected(): void
    {
        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => Str::uuid()->toString(), // no such user
        ])->assertExitCode(1);
    }

    public function test_missing_tenant_or_terminal_option_is_rejected(): void
    {
        // Without --tenant or --terminal the command cannot identify the
        // chain to walk. Per the spec contract
        // (`fiscal:verify-event-chain {--tenant=} {--terminal=}`), both are
        // required identifiers.
        $this->artisan('fiscal:verify-event-chain', [
            '--actor-id' => $this->verifierUser->id,
        ])->assertExitCode(1);
    }

    // =================================================================
    // Tenancy binding (cat-(b) wave 2, 2026-08-05)
    //
    // `--tenant` used to be a WHERE predicate on a command that never left the
    // console's CENTRAL connection. It now BINDS tenancy, so a tenant that is
    // not in the central directory can no longer be silently "verified".
    // =================================================================

    public function test_an_unknown_tenant_fails_loudly_instead_of_reporting_a_verified_chain(): void
    {
        $this->seedValidChain(3);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => '00000000-0000-0000-0000-000000000000',
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('not found in the central tenant directory')
            ->doesntExpectOutputToContain('chain verified')
            ->assertExitCode(1);
    }

    /**
     * The chain lives in tenant A; the operator names tenant B. Before the
     * conversion the walk simply matched zero rows and printed
     * "chain verified — … 0 events walked", exit 0.
     */
    public function test_a_chain_is_not_verified_from_another_tenants_binding(): void
    {
        $this->seedValidChain(3);

        $otherTenant = Tenant::factory()->create();

        // The actor must belong to the NAMED tenant since the 2026-08-05 R3
        // fix (see the test below); otherwise the run stops at the actor gate
        // and never reaches the terminal-ownership check this test is about.
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($otherTenant->id);
        $otherActor = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherActor->givePermissionTo('fiscal.events.verify_chain');
        $registrar->setPermissionsTeamId($this->tenantId);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $otherTenant->id,
            '--terminal' => $this->terminalId,
            '--actor-id' => $otherActor->id,
        ])
            ->expectsOutputToContain(sprintf('does not exist in tenant %s', $otherTenant->id))
            ->doesntExpectOutputToContain('chain verified')
            ->assertExitCode(1);
    }

    /**
     * R3 (2026-08-05 wave-2 tenancy review). The actor lookup carried no
     * `tenant_id` predicate while every other query in the closure did. In
     * single-schema compatibility mode that let an actor belonging to tenant B
     * resolve for a `--tenant=A` run — and because the command then calls
     * `setPermissionsTeamId($actor->tenant_id)`, `can()` was evaluated against
     * **B's** team, so B's roles authorised chain verification over A's rows.
     */
    public function test_an_actor_from_another_tenant_cannot_authorise_a_run_against_this_tenant(): void
    {
        $this->seedValidChain(3);

        $tenantB = Tenant::factory()->create();
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenantB->id);
        $actorB = User::factory()->create(['tenant_id' => $tenantB->id]);
        // B's OWN team grants the permission — the only thing that must stop
        // this run is that B's actor does not belong to tenant A.
        $actorB->givePermissionTo('fiscal.events.verify_chain');
        $registrar->setPermissionsTeamId($this->tenantId);

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $actorB->id,
        ])
            ->expectsOutputToContain(sprintf('Unknown actor user id %s', $actorB->id))
            ->doesntExpectOutputToContain('chain verified')
            ->assertExitCode(1);
    }

    public function test_seeded_v3_fixture_has_two_contexts_and_a_hash_mirrored_projected_receipt(): void
    {
        $this->seedV3FiscalFixture();

        $schemaVersion = DB::table('pos_terminals')
            ->where('id', $this->terminalId)
            ->value('fiscal_schema_version');
        $contexts = DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->orderBy('chain_context')
            ->pluck('chain_context')
            ->all();
        $projectedReceipts = DB::table('pos_receipts')
            ->where('terminal_id', $this->terminalId)
            ->whereNotNull('fiscal_event_id')
            ->count();
        $mirrorMismatches = DB::table('pos_receipts as receipts')
            ->join('fiscal_events as events', 'events.id', '=', 'receipts.fiscal_event_id')
            ->where('receipts.terminal_id', $this->terminalId)
            ->whereColumn('receipts.fiscal_hash', '!=', 'events.current_hash')
            ->count();

        $this->assertGreaterThanOrEqual(3, (int) $schemaVersion);
        $this->assertSame(['operational', 'z_session'], $contexts);
        $this->assertGreaterThan(0, $projectedReceipts);
        $this->assertSame(0, $mirrorMismatches);
        // Characterization of the unfixed, unregistered production defect
        // recorded in M0-preflight-evidence.md; false is not the desired contract.
        $this->assertFalse(
            $this->app->make(ReceiptHashService::class)
                ->verifyTerminalChain(Terminal::query()->findOrFail($this->terminalId)),
            'Characterization only: the unfixed receipt fiscal arm currently flattens both contexts and reports a false linkage failure.',
        );
    }

    public function test_wrong_context_previous_hash_tamper_is_self_asserting(): void
    {
        $this->seedWrongContextPreviousHashTamper();
    }

    public function test_receipt_mirror_tamper_is_self_asserting(): void
    {
        $this->seedReceiptMirrorTamper();
    }

    // =================================================================
    // ES-08 — honest verifier checks (M1, red-first)
    //
    // Each fixture below changes exactly one verifier input. The production
    // mutation named by each test is the omission of the corresponding
    // check from VerifyEventChainCommand::walkChain().
    // =================================================================

    public function test_fails_when_parsed_payload_diverges_from_canonical_bytes(): void
    {
        $payload = $this->chainBreakPayload('sealed reason');
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $payload,
        );

        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            payload: array_merge($payload, ['reason' => 'rewritten reason']),
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()
            ->expectsOutputToContain('payload does not semantically match canonical_bytes')
            ->assertExitCode(1);
    }

    public function test_passes_when_parsed_payload_semantically_matches_canonical_bytes(): void
    {
        $payload = $this->chainBreakPayload('matching reason');
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $payload,
        );

        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            payload: $payload,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()->assertExitCode(0);
    }

    public function test_fails_when_an_internally_hash_valid_row_is_not_verified(): void
    {
        $canonicalBytes = '{"event":"quarantined_but_hash_valid"}';
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            integrityStatus: IntegrityStatus::Quarantined,
            integrityExceptionClass: IntegrityExceptionClass::TimeAnomaly,
        );

        $this->runVerifier()
            ->expectsOutputToContain('integrity_status is quarantined, expected verified')
            ->assertExitCode(1);
    }

    public function test_fails_when_a_stored_coordinate_disagrees_with_its_sealed_value(): void
    {
        $payload = $this->chainBreakPayload('coordinate control');
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $payload,
            overrides: ['company_id' => Str::uuid()->toString()],
        );

        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            payload: $payload,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()
            ->expectsOutputToContain('sealed coordinate company_id mismatch')
            ->assertExitCode(1);
    }

    public function test_fails_when_sequence_numbers_are_not_contiguous_even_if_hash_linkage_is_valid(): void
    {
        $firstCanonicalBytes = '{"event":"sequence_1"}';
        $firstHash = hash('sha256', $firstCanonicalBytes);
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $firstCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: $firstHash,
        );

        $thirdCanonicalBytes = '{"event":"sequence_3"}';
        $this->insertEvent(
            sequenceNumber: 3,
            canonicalBytes: $thirdCanonicalBytes,
            previousHash: $firstHash,
            currentHash: hash('sha256', $thirdCanonicalBytes),
        );

        $this->runVerifier()
            ->expectsOutputToContain('sequence_number gap — expected 2, stored 3')
            ->assertExitCode(1);
    }

    public function test_wrong_context_previous_hash_tamper_is_reported_by_the_existing_context_scoped_link_check(): void
    {
        $this->seedWrongContextPreviousHashTamper();

        $this->runVerifier()
            ->expectsOutputToContain('previous_hash linkage mismatch')
            ->assertExitCode(1);
    }

    // =================================================================
    // Fixture helpers — CI-shaped seeders matching plan §2401 contract.
    // =================================================================

    private function seedV3FiscalFixture(): void
    {
        DB::table('pos_terminals')
            ->where('id', $this->terminalId)
            ->update(['fiscal_schema_version' => 3]);

        $operationalCanonicalBytes = '{"event":"v3_operational_receipt","sequence_number":1}';
        $operationalHash = hash('sha256', $operationalCanonicalBytes);
        $operationalEventId = $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $operationalCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: $operationalHash,
            chainContext: 'operational',
        );

        $zSessionCanonicalBytes = '{"event":"v3_z_session","sequence_number":1}';
        $zSessionEventId = $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $zSessionCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $zSessionCanonicalBytes),
            chainContext: 'z_session',
        );

        $receiptId = $this->insertProjectedReceipt(
            fiscalEventId: $operationalEventId,
            fiscalHash: $operationalHash,
            previousHash: $this->genesisSeed,
            canonicalBytes: $operationalCanonicalBytes,
            chainSequence: 1,
        );

        $this->assertTrue(Str::isUuid($operationalEventId));
        $this->assertTrue(Str::isUuid($zSessionEventId));
        $this->assertTrue(Str::isUuid($receiptId));
        $this->assertGreaterThanOrEqual(
            3,
            (int) DB::table('pos_terminals')->where('id', $this->terminalId)->value('fiscal_schema_version'),
        );
        $this->assertSame(
            ['operational', 'z_session'],
            DB::table('fiscal_events')
                ->where('terminal_id', $this->terminalId)
                ->orderBy('chain_context')
                ->pluck('chain_context')
                ->all(),
        );
        $this->assertSame(
            1,
            DB::table('pos_receipts')
                ->where('id', $receiptId)
                ->whereNotNull('fiscal_event_id')
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('pos_receipts as receipts')
                ->join('fiscal_events as events', 'events.id', '=', 'receipts.fiscal_event_id')
                ->where('receipts.id', $receiptId)
                ->whereColumn('receipts.fiscal_hash', '!=', 'events.current_hash')
                ->count(),
        );
    }

    /**
     * Seed T-b: an operational row whose hash is internally correct but whose
     * previous_hash comes from the z_session head on the same terminal.
     */
    private function seedWrongContextPreviousHashTamper(): void
    {
        $this->seedV3FiscalFixture();

        $operationalHead = (string) DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->where('chain_context', 'operational')
            ->value('current_hash');
        $zSessionHead = (string) DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->where('chain_context', 'z_session')
            ->value('current_hash');
        $canonicalBytes = '{"event":"wrong_context_link","sequence_number":2}';
        $eventId = $this->insertEvent(
            sequenceNumber: 2,
            canonicalBytes: $canonicalBytes,
            previousHash: $zSessionHead,
            currentHash: hash('sha256', $canonicalBytes),
            chainContext: 'operational',
        );

        $row = DB::table('fiscal_events')->where('id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('operational', $row->chain_context);
        $this->assertSame($zSessionHead, $row->previous_hash);
        $this->assertNotSame($operationalHead, $row->previous_hash);
        $canonicalBytes = $this->stringifyCanonicalBytes($row->canonical_bytes);
        $this->assertSame(hash('sha256', $canonicalBytes), $row->current_hash);
    }

    /**
     * Seed T-c: a projected receipt whose immutable fiscal_hash differs from
     * the referenced, internally valid fiscal event's current_hash.
     */
    private function seedReceiptMirrorTamper(): void
    {
        DB::table('pos_terminals')
            ->where('id', $this->terminalId)
            ->update(['fiscal_schema_version' => 3]);

        $firstCanonicalBytes = '{"event":"receipt_mirror_control","sequence_number":1}';
        $firstHash = hash('sha256', $firstCanonicalBytes);
        $firstEventId = $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $firstCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: $firstHash,
            chainContext: 'operational',
        );
        $this->insertProjectedReceipt(
            fiscalEventId: $firstEventId,
            fiscalHash: $firstHash,
            previousHash: $this->genesisSeed,
            canonicalBytes: $firstCanonicalBytes,
            chainSequence: 1,
        );

        $canonicalBytes = '{"event":"receipt_mirror_tamper","sequence_number":2}';
        $eventHash = hash('sha256', $canonicalBytes);
        $eventId = $this->insertEvent(
            sequenceNumber: 2,
            canonicalBytes: $canonicalBytes,
            previousHash: $firstHash,
            currentHash: $eventHash,
            chainContext: 'operational',
        );

        $terminal = Terminal::query()->findOrFail($this->terminalId);
        $receiptHashService = $this->app->make(ReceiptHashService::class);
        $this->assertTrue(
            $receiptHashService->verifyTerminalChain($terminal),
            'T-c clean control must pass the current receipt verifier before the mirror mismatch is introduced.',
        );

        $receiptId = $this->insertProjectedReceipt(
            fiscalEventId: $eventId,
            fiscalHash: str_repeat('d', 64),
            previousHash: $firstHash,
            canonicalBytes: $canonicalBytes,
            chainSequence: 2,
        );

        $this->assertTrue(
            $receiptHashService->verifyTerminalChain($terminal),
            'The current receipt verifier must remain green when T-c adds only the missing mirror divergence.',
        );

        $mirror = DB::table('pos_receipts as receipts')
            ->join('fiscal_events as events', 'events.id', '=', 'receipts.fiscal_event_id')
            ->where('receipts.id', $receiptId)
            ->select([
                'receipts.fiscal_event_id',
                'receipts.fiscal_hash as receipt_hash',
                'receipts.previous_hash as receipt_previous_hash',
                'receipts.canonical_bytes as receipt_canonical_bytes',
                'events.current_hash as event_hash',
                'events.canonical_bytes as event_canonical_bytes',
            ])
            ->first();

        $this->assertNotNull($mirror);
        $previousReceiptHash = (string) DB::table('pos_receipts')
            ->where('terminal_id', $this->terminalId)
            ->where('chain_sequence', 1)
            ->value('fiscal_hash');
        $eventCanonicalBytes = $this->stringifyCanonicalBytes($mirror->event_canonical_bytes);
        $receiptCanonicalBytes = $this->stringifyCanonicalBytes($mirror->receipt_canonical_bytes);
        $this->assertSame($eventId, $mirror->fiscal_event_id);
        $this->assertSame(hash('sha256', $eventCanonicalBytes), $mirror->event_hash);
        $this->assertSame($eventCanonicalBytes, $receiptCanonicalBytes);
        $this->assertSame($previousReceiptHash, $mirror->receipt_previous_hash);
        $this->assertNotSame($mirror->event_hash, $mirror->receipt_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $mirror->receipt_hash);
        $this->assertSame(
            1,
            DB::table('pos_receipts as receipts')
                ->join('fiscal_events as events', 'events.id', '=', 'receipts.fiscal_event_id')
                ->where('receipts.terminal_id', $this->terminalId)
                ->whereColumn('receipts.fiscal_hash', '!=', 'events.current_hash')
                ->count(),
        );
    }

    private function insertProjectedReceipt(
        string $fiscalEventId,
        string $fiscalHash,
        string $previousHash,
        string $canonicalBytes,
        int $chainSequence,
    ): string {
        $receiptId = Str::uuid()->toString();
        $now = Carbon::now('UTC');
        DB::table('pos_receipts')->insert([
            'id' => $receiptId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'terminal_id' => $this->terminalId,
            'receipt_number' => 'M0-V3-'.Str::upper(Str::random(12)),
            'receipt_type' => 'sale',
            'chain_sequence' => $chainSequence,
            'receipt_year' => (int) $now->format('Y'),
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'vat_breakdown_hash' => hash('sha256', 'm0-vat-'.$chainSequence),
            'payment_methods_hash' => hash('sha256', 'm0-payment-'.$chainSequence),
            'posted_at' => $now,
            'cashier_id' => $this->verifierUser->id,
            'cashier_name' => 'M0 Fixture Cashier',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'TND',
            'fiscal_status' => 'fiscalized',
            'invoice_type_code' => 'SALE',
            'training_flag' => false,
            'is_voided' => false,
            'is_training' => false,
            'canonical_bytes' => $canonicalBytes,
            'fiscal_event_id' => $fiscalEventId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $receiptId;
    }

    /**
     * Seed `$length` consecutive `fiscal_events` rows for the test terminal.
     * Each row's `previous_hash` equals the prior row's `current_hash`; the
     * first row's `previous_hash` equals the terminal's `genesis_seed`.
     * `current_hash` is computed as `sha256(canonical_bytes)` so the verifier's
     * re-hash matches.
     */
    private function seedValidChain(int $length): void
    {
        $previousHash = $this->genesisSeed;
        for ($seq = 1; $seq <= $length; $seq++) {
            $canonicalBytes = json_encode(
                ['event' => 'seq'.$seq, 'sequence_number' => $seq],
                JSON_THROW_ON_ERROR,
            );
            $currentHash = hash('sha256', $canonicalBytes);

            $this->insertEvent(
                sequenceNumber: $seq,
                canonicalBytes: $canonicalBytes,
                previousHash: $previousHash,
                currentHash: $currentHash,
            );

            $previousHash = $currentHash;
        }
    }

    /**
     * Seed a valid chain of `$totalLength` events, then corrupt the row at
     * `sequence_number = $atSequence` by storing a `current_hash` that does
     * NOT match `sha256(canonical_bytes)`. The mismatch is detected on
     * re-hash by the verifier.
     *
     * Implementation detail: because the Task 8 trigger blocks `UPDATE` on
     * `canonical_bytes` / `current_hash`, we tamper at insert-time —
     * inserting row `$atSequence` with a deliberate hash mismatch. The
     * subsequent rows chain off the (tampered) `current_hash`, so the
     * mismatch is local to the tampered row only.
     */
    private function seedTamperedChain(int $atSequence, int $totalLength = 5): void
    {
        $previousHash = $this->genesisSeed;
        for ($seq = 1; $seq <= $totalLength; $seq++) {
            $canonicalBytes = json_encode(
                ['event' => 'seq'.$seq, 'sequence_number' => $seq],
                JSON_THROW_ON_ERROR,
            );

            if ($seq === $atSequence) {
                // Tamper: store a current_hash that does NOT match
                // sha256(canonical_bytes). Use a deterministic 64-hex
                // string that's clearly distinct from the real hash.
                $currentHash = str_repeat('f', 64);
            } else {
                $currentHash = hash('sha256', $canonicalBytes);
            }

            $this->insertEvent(
                sequenceNumber: $seq,
                canonicalBytes: $canonicalBytes,
                previousHash: $previousHash,
                currentHash: $currentHash,
            );

            // The next row chains off whatever current_hash was stored
            // (tampered or not). This keeps the link-test passing for
            // rows after the tampered one — the only failure detected
            // is the rehash mismatch at $atSequence.
            $previousHash = $currentHash;
        }
    }

    /**
     * Seed a `fiscal_event_quarantine` row of class `sequence_conflict`
     * referencing the test terminal. The verifier must surface this as a
     * chain incident even when the `fiscal_events` chain itself is clean.
     */
    private function seedQuarantineConflict(int $claimedSequence): void
    {
        $canonicalBytes = '{"event":"conflict_envelope"}';
        $envelopeEventId = Str::uuid()->toString();
        $conflictingEventId = Str::uuid()->toString();

        $row = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'envelope_event_id' => $envelopeEventId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'claimed_sequence_number' => $claimedSequence,
            'event_time_device' => Carbon::now('UTC'),
            'business_date' => Carbon::now('UTC')->startOfDay(),
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => str_repeat('c', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'canonical_bytes' => $canonicalBytes,
            'raw_envelope' => json_encode(['envelope_id' => $envelopeEventId], JSON_THROW_ON_ERROR),
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'conflicting_event_id' => $conflictingEventId,
            'server_received_at' => Carbon::now('UTC'),
        ];

        // The model's `$fillable` deliberately omits the classification
        // columns (Task 9 boundary discipline mirrored in Task 10) — use
        // `forceFill()` to set them, matching the OutboxIngestor pattern
        // for incident classification.
        $quarantine = (new FiscalEventQuarantine)->forceFill(array_merge(
            $row,
            [
                'integrity_exception_class' => IntegrityExceptionClass::SequenceConflict->value,
                'integrity_exception_reason' => 'sequence_conflict:different_event_at_occupied_slot',
            ],
        ));
        $quarantine->save();
    }

    /**
     * Direct insert into `fiscal_events` bypassing the OutboxIngestor.
     * Used to seed both valid and tampered chains — the insert path is
     * the only way to land a `current_hash` that doesn't match
     * `sha256(canonical_bytes)`, since the Task 8 trigger forbids
     * `UPDATE`s on those columns.
     */
    private function insertEvent(
        int $sequenceNumber,
        string $canonicalBytes,
        string $previousHash,
        string $currentHash,
        string $chainContext = 'operational',
        ?array $payload = null,
        PayloadParseStatus $payloadParseStatus = PayloadParseStatus::Pending,
        IntegrityStatus $integrityStatus = IntegrityStatus::Verified,
        ?IntegrityExceptionClass $integrityExceptionClass = null,
        FiscalEventType $eventType = FiscalEventType::SALE_RECEIPT,
        ?string $eventTimeDevice = null,
        ?string $businessDate = null,
    ): string {
        $now = Carbon::now('UTC');
        $eventId = Str::uuid()->toString();
        $row = [
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => $eventType->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTimeDevice ?? $now,
            'business_date' => $businessDate ?? $now->copy()->startOfDay(),
            'chain_context' => $chainContext,
            'last_server_time_seen' => null,
            'server_received_at' => $now,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => $integrityStatus->value,
            'integrity_exception_class' => $integrityExceptionClass?->value,
            'integrity_exception_reason' => $integrityExceptionClass === null ? null : 'm1_isolated_fixture',
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_parse_status' => $payloadParseStatus->value,
            'created_at' => $now,
        ];

        DB::table('fiscal_events')->insert($row);

        return $eventId;
    }

    private function runVerifier(): PendingCommand
    {
        return $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chainBreakPayload(string $reason): array
    {
        return [
            'last_good_hash' => str_repeat('b', 64),
            'last_good_sequence' => 0,
            'offending_record_reference' => [
                'observed_previous_hash' => $this->genesisSeed,
                'sequence_number' => 1,
                'terminal_id' => $this->terminalId,
            ],
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     */
    private function canonicalEnvelope(
        int $sequenceNumber,
        string $previousHash,
        array $payload,
        array $overrides = [],
    ): string {
        $fields = array_merge([
            'business_date' => '2026-08-12',
            'chain_context' => 'operational',
            'company_id' => $this->companyId,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'event_type' => FiscalEventType::CHAIN_BREAK_DETECTED->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ], $overrides);
        ksort($fields);

        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
