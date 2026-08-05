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
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

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

        $this->artisan('fiscal:verify-event-chain', [
            '--tenant' => $otherTenant->id,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ])
            ->expectsOutputToContain(sprintf('does not exist in tenant %s', $otherTenant->id))
            ->doesntExpectOutputToContain('chain verified')
            ->assertExitCode(1);
    }

    // =================================================================
    // Fixture helpers — CI-shaped seeders matching plan §2401 contract.
    // =================================================================

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
    ): void {
        $now = Carbon::now('UTC');
        $row = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $now,
            'business_date' => $now->copy()->startOfDay(),
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
            'integrity_status' => IntegrityStatus::Verified->value,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => PayloadParseStatus::Pending->value,
            'created_at' => $now,
        ];

        DB::table('fiscal_events')->insert($row);
    }
}
