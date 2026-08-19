<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Services\TerminalRegistrySnapshotService;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\Fiscal\GoldenFixtureBuilder;
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

    public function test_verifies_the_existing_server_authored_snapshot_envelope(): void
    {
        $event = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            terminalId: $this->terminalId,
            operatorId: $this->verifierUser->id,
        );

        $this->assertStringNotContainsString('"chain_context"', $this->stringifyCanonicalBytes($event->canonical_bytes));

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('chain verified', $output);
    }

    public function test_verifies_the_existing_virtual_admin_server_authored_envelopes(): void
    {
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'customer_category' => CustomerCategory::Business,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);
        $service = $this->app->make(VirtualAdminFiscalEventService::class);
        $first = $service->appendAccountStatusChanged(
            partner: $partner,
            oldStatus: CustomerAccountStatus::Active,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: $this->verifierUser->id,
            reason: 'Verifier compatibility fixture',
        );
        $second = $service->appendDepositReceipt(
            partner: $partner,
            actorUserId: $this->verifierUser->id,
            actorName: $this->verifierUser->name,
            currencyCode: 'TND',
            amount: '10.000',
            methodCode: 'cash',
            repositoryId: null,
            notes: null,
        );

        $this->assertStringNotContainsString('"chain_context"', $this->stringifyCanonicalBytes($first->canonical_bytes));
        $this->assertStringNotContainsString('"chain_context"', $this->stringifyCanonicalBytes($second->canonical_bytes));

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $first->terminal_id,
            '--actor-id' => $this->verifierUser->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('chain verified', $output);
    }

    public function test_missing_chain_context_remains_a_failure_for_device_authored_events(): void
    {
        $payload = $this->chainBreakPayload('device-authored omission');
        $envelope = json_decode($this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $payload,
        ), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($envelope);
        unset($envelope['chain_context']);
        $canonicalBytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

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
            ->expectsOutputToContain('envelope_field_missing:chain_context')
            ->assertExitCode(1);
    }

    public function test_legacy_server_compatibility_rejects_an_extra_envelope_field(): void
    {
        $first = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            terminalId: $this->terminalId,
            operatorId: $this->verifierUser->id,
        );
        $envelope = json_decode($this->stringifyCanonicalBytes($first->canonical_bytes), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($envelope);
        $envelope['sequence_number'] = 2;
        $envelope['previous_hash'] = $first->current_hash;
        $envelope['unexpected'] = 'must fail closed';
        ksort($envelope);
        $canonicalBytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->insertEvent(
            sequenceNumber: 2,
            canonicalBytes: $canonicalBytes,
            previousHash: $first->current_hash,
            currentHash: hash('sha256', $canonicalBytes),
            payload: $envelope['payload'],
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
            eventTimeDevice: str_replace(['T', 'Z'], [' ', ''], (string) $envelope['event_time_device']),
            businessDate: (string) $envelope['business_date'],
        );

        $this->runVerifier()
            ->expectsOutputToContain('envelope_field_missing:chain_context')
            ->assertExitCode(1);
    }

    public function test_legacy_server_compatibility_still_checks_every_sealed_coordinate(): void
    {
        $first = $this->app->make(TerminalRegistrySnapshotService::class)->emitInitialSnapshot(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            terminalId: $this->terminalId,
            operatorId: $this->verifierUser->id,
        );
        $envelope = json_decode($this->stringifyCanonicalBytes($first->canonical_bytes), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($envelope);
        $envelope['company_id'] = Str::uuid()->toString();
        $envelope['sequence_number'] = 2;
        $envelope['previous_hash'] = $first->current_hash;
        ksort($envelope);
        $canonicalBytes = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->insertEvent(
            sequenceNumber: 2,
            canonicalBytes: $canonicalBytes,
            previousHash: $first->current_hash,
            currentHash: hash('sha256', $canonicalBytes),
            payload: $envelope['payload'],
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
            eventTimeDevice: str_replace(['T', 'Z'], [' ', ''], (string) $envelope['event_time_device']),
            businessDate: (string) $envelope['business_date'],
        );

        $this->runVerifier()
            ->expectsOutputToContain('sealed coordinate company_id mismatch')
            ->assertExitCode(1);
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
        $bogusGenesis = str_repeat('b', 64);
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $bogusGenesis,
            payload: $this->chainBreakPayload('bogus genesis link'),
        );
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $bogusGenesis,
            currentHash: hash('sha256', $canonicalBytes),
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain('previous_hash linkage mismatch')
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
        // M2 fix round (STOP C ruling, docs/handoff/reviews/es-wave-a0/
        // ORCHESTRATOR-RULING-2026-08-19-m2-stop-c.md). This assertion was a
        // characterization of the context-flattening defect recorded in
        // M0-preflight-evidence.md; the fiscal arm now walks each
        // (company_id, chain_context) as its own chain from genesis_seed, so
        // the CLEAN two-context v3 fixture must verify TRUE.
        $this->assertTrue(
            $this->app->make(ReceiptHashService::class)
                ->verifyTerminalChain(Terminal::query()->findOrFail($this->terminalId)),
            'The clean two-context v3 fixture must verify: each chain_context is its own chain from genesis_seed.',
        );
    }

    public function test_seeded_v3_operational_context_is_a_clean_verifier_control(): void
    {
        $this->seedV3FiscalFixture();

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--chain-context' => 'operational',
            '--actor-id' => $this->verifierUser->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('chain verified', $output);
    }

    public function test_seeded_v3_z_session_context_is_a_clean_verifier_control(): void
    {
        $this->seedV3FiscalFixture();

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--chain-context' => 'z_session',
            '--actor-id' => $this->verifierUser->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('chain verified', $output);
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

    /**
     * ES-06 (M3) — the ambiguity guard on the sealed-payload recovery.
     *
     * `json_decode` keeps the LAST occurrence of a duplicated key; the strict
     * parser rejects the document outright. On such bytes the two disagree
     * about what was sealed, so recovery must refuse and the verifier must
     * fall back to the fail-closed sentence rather than assert a divergence
     * verdict it cannot justify. Without the round-trip guard in
     * `recoverSealedPayloadFromFrozenBytes()` this row would be judged
     * against the SECOND `payload` member.
     */
    public function test_sealed_payload_recovery_refuses_ambiguous_duplicate_key_envelopes(): void
    {
        $sealed = $this->chainBreakPayload('sealed reason');
        $shadow = $this->chainBreakPayload('shadow reason');

        // Hand-built bytes: a duplicated `payload` key. Both members are
        // well-formed; only their order distinguishes them.
        $canonicalBytes = '{"payload":'.json_encode($sealed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            .',"payload":'.json_encode($shadow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).'}';

        // The stored payload equals the member `json_decode` would win with,
        // so a guard-less recovery would find them EQUAL and stay silent.
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            payload: $shadow,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()
            ->expectsOutputToContain('canonical payload could not be derived')
            ->assertExitCode(1);
    }

    public function test_fails_when_an_internally_hash_valid_row_is_not_verified(): void
    {
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $this->chainBreakPayload('quarantined but hash valid'),
        );
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $canonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $canonicalBytes),
            integrityStatus: IntegrityStatus::Quarantined,
            integrityExceptionClass: IntegrityExceptionClass::TimeAnomaly,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
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

    public function test_fails_when_pending_row_stored_coordinate_disagrees_with_its_sealed_value(): void
    {
        $payload = $this->chainBreakPayload('pending coordinate mismatch');
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
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()
            ->expectsOutputToContain('sealed coordinate company_id mismatch')
            ->assertExitCode(1);
    }

    public function test_passes_when_pending_row_sealed_coordinates_match(): void
    {
        $payload = $this->chainBreakPayload('pending coordinate control');
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
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()->assertExitCode(0);
    }

    public function test_fails_when_sequence_numbers_are_not_contiguous_even_if_hash_linkage_is_valid(): void
    {
        $firstCanonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $this->chainBreakPayload('sequence 1'),
        );
        $firstHash = hash('sha256', $firstCanonicalBytes);
        $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $firstCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: $firstHash,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $thirdCanonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 3,
            previousHash: $firstHash,
            payload: $this->chainBreakPayload('sequence 3'),
        );
        $this->insertEvent(
            sequenceNumber: 3,
            canonicalBytes: $thirdCanonicalBytes,
            previousHash: $firstHash,
            currentHash: hash('sha256', $thirdCanonicalBytes),
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $this->runVerifier()
            ->expectsOutputToContain('sequence_number gap — expected 2, stored 3')
            ->assertExitCode(1);
    }

    public function test_wrong_context_previous_hash_tamper_is_reported_by_the_existing_context_scoped_link_check(): void
    {
        $this->seedWrongContextPreviousHashTamper();

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--chain-context' => 'operational',
            '--actor-id' => $this->verifierUser->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertSame(1, substr_count($output, 'CHAIN BREAK at sequence_number'));
        $this->assertStringContainsString('previous_hash linkage mismatch', $output);
        $this->assertStringContainsString('1 chain incidents, 0 quarantine incidents', $output);
        $this->assertStringNotContainsString('sealed coordinates could not be derived', $output);
        $this->assertStringNotContainsString('payload does not semantically match', $output);
        $this->assertStringNotContainsString('current_hash mismatch', $output);
    }

    // =================================================================
    // Fixture helpers — CI-shaped seeders matching plan §2401 contract.
    // =================================================================

    private function seedV3FiscalFixture(): void
    {
        DB::table('pos_terminals')
            ->where('id', $this->terminalId)
            ->update(['fiscal_schema_version' => 3]);

        $operationalPayload = GoldenFixtureBuilder::all()['F-01-baseline-eur'];
        $operationalCanonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $operationalPayload,
            eventType: FiscalEventType::SALE_RECEIPT,
            chainContext: 'operational',
        );
        $operationalHash = hash('sha256', $operationalCanonicalBytes);
        $operationalEventId = $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $operationalCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: $operationalHash,
            chainContext: 'operational',
            payload: $operationalPayload,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::SALE_RECEIPT,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
        );

        $zSessionPayload = [
            'business_date' => '2026-08-12',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'opened_at_device' => '2026-08-12T07:00:00.000Z',
            'opening_float_amount' => '100.000',
            'operator_id' => $this->operatorId,
            'operator_name' => 'M1 Fixture Operator',
            'session_id' => '00000000-0000-4000-8000-000000000101',
            'shift_id' => '00000000-0000-4000-8000-000000000102',
            'shift_number' => 1,
            'terminal_id' => $this->terminalId,
            'terminal_label' => 'M1 Fixture Terminal',
            'training_flag' => false,
        ];
        $zSessionCanonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 1,
            previousHash: $this->genesisSeed,
            payload: $zSessionPayload,
            eventType: FiscalEventType::SESSION_OPEN,
            chainContext: 'z_session',
        );
        $zSessionEventId = $this->insertEvent(
            sequenceNumber: 1,
            canonicalBytes: $zSessionCanonicalBytes,
            previousHash: $this->genesisSeed,
            currentHash: hash('sha256', $zSessionCanonicalBytes),
            chainContext: 'z_session',
            payload: $zSessionPayload,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::SESSION_OPEN,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
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
        $payload = $this->chainBreakPayload('wrong context link');
        $canonicalBytes = $this->canonicalEnvelope(
            sequenceNumber: 2,
            previousHash: $zSessionHead,
            payload: $payload,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            chainContext: 'operational',
        );
        $eventId = $this->insertEvent(
            sequenceNumber: 2,
            canonicalBytes: $canonicalBytes,
            previousHash: $zSessionHead,
            currentHash: hash('sha256', $canonicalBytes),
            chainContext: 'operational',
            payload: $payload,
            payloadParseStatus: PayloadParseStatus::Parsed,
            eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
            eventTimeDevice: '2026-08-12T07:00:00Z',
            businessDate: '2026-08-12',
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

        $this->assertFalse(
            $receiptHashService->verifyTerminalChain($terminal),
            'The receipt verifier must fail when T-c adds the projected receipt/event mirror divergence.',
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
            $canonicalBytes = $this->canonicalEnvelope(
                sequenceNumber: $seq,
                previousHash: $previousHash,
                payload: $this->chainBreakPayload('valid chain fixture '.$seq),
            );
            $currentHash = hash('sha256', $canonicalBytes);

            $this->insertEvent(
                sequenceNumber: $seq,
                canonicalBytes: $canonicalBytes,
                previousHash: $previousHash,
                currentHash: $currentHash,
                eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
                eventTimeDevice: '2026-08-12T07:00:00Z',
                businessDate: '2026-08-12',
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
            $canonicalBytes = $this->canonicalEnvelope(
                sequenceNumber: $seq,
                previousHash: $previousHash,
                payload: $this->chainBreakPayload('tampered chain fixture '.$seq),
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
                eventType: FiscalEventType::CHAIN_BREAK_DETECTED,
                eventTimeDevice: '2026-08-12T07:00:00Z',
                businessDate: '2026-08-12',
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
        FiscalEventType $eventType = FiscalEventType::CHAIN_BREAK_DETECTED,
        string $chainContext = 'operational',
    ): string {
        $fields = array_merge([
            'business_date' => '2026-08-12',
            'chain_context' => $chainContext,
            'company_id' => $this->companyId,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'event_type' => $eventType->value,
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
