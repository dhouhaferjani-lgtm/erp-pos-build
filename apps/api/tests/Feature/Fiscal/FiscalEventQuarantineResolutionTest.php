<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ES-17 — `fiscal_event_quarantine` had no resolution path at all.
 *
 * Contract of record: `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`
 * revision 2, clauses **17-A … 17-G**, falsifiers **F17-1 … F17-7**. The M3
 * review approved ES-17's contract as written (it was unchanged by fix round 1).
 * Each clause is quoted verbatim at the test that discharges it.
 *
 * **The defect.** `FiscalEventQuarantine.php:20-22` documents the table as
 * MUTABLE precisely so "the resolution flow writes `resolved_at` / `resolved_by`
 * when an admin clears the incident". Both columns are declared (`:57-58`), cast
 * (`:151`), deliberately non-fillable (`:84-90`) — and, before this milestone,
 * only ever READ: `VerifyEventChainCommand::reportQuarantineIncidents()` (cited
 * by SYMBOL, not by line — that file's line numbers have drifted three times in
 * this wave) reports every `resolved_at IS NULL` row as a chain incident, and
 * `Nf525DataProvider::buildQuarantineSection()` exports `resolved_at` — and only
 * `resolved_at` — into the §8 audit section. No writer existed anywhere in
 * `app/`. Consequence, and the reason this row is in the A0 lane at all:
 * **one `sequence_conflict` envelope made `fiscal:verify-event-chain` return
 * exit 1 for that terminal forever**, because the only condition that clears
 * the incident was a column nothing wrote. The command this wave exists to make
 * trustworthy had a permanently-red state with no exit.
 *
 * **What the stamp is, and is not.** It records that a human ADJUDICATED the
 * incident. It asserts nothing about the envelope's contents — `sequence_conflict`
 * means two different events claimed one sequence slot, and recording that
 * someone looked at it says nothing about which was right (F17-2). The envelope
 * is never admitted into `fiscal_events` (F17-1), the underlying conflict is not
 * resolved, and no approval / second-approver flow is built — that is **D-8**,
 * owner-gated, and out of this wave (F17-7).
 */
final class FiscalEventQuarantineResolutionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private string $genesisSeed;

    /** Holds `fiscal.events.resolve_quarantine` — the resolver principal (17-E). */
    private User $resolver;

    /** Holds `fiscal.events.verify_chain` — drives the 17-C end-to-end check. */
    private User $verifierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('a', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $this->terminalId = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => $this->genesisSeed,
        ])->id;

        $this->operatorId = Str::uuid()->toString();

        $this->resolver = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Quarantine resolver']);
        UserCompanyMembership::create([
            'user_id' => $this->resolver->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);
        $this->resolver->givePermissionTo('fiscal.events.resolve_quarantine');

        $this->verifierUser = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Chain verifier']);
        $this->verifierUser->givePermissionTo('fiscal.events.verify_chain');
    }

    // =================================================================
    // 17-E, FIRST CHECK — "The action is permission-gated, reusing an
    // EXISTING seeded permission. Named candidate:
    // `fiscal.events.resolve_quarantine`, which already exists and already
    // gates the sibling parse-failure resolver (`ParseFailureResumeTest.php:138`).
    // … **If the fixture shows the intended principal does not hold it, STOP
    // `blocked_owner` rather than inventing a permission** — the same rule M4
    // carries for ES-42."
    //
    // Run FIRST and asserted explicitly, so the STOP condition is a checked
    // fact rather than an assumption made on the way past.
    // =================================================================

    public function test_the_reused_permission_exists_in_the_seeded_set_and_the_resolver_principal_holds_it(): void
    {
        $this->assertTrue(
            Permission::query()->where('name', 'fiscal.events.resolve_quarantine')->exists(),
            '17-E STOP CONDITION: `fiscal.events.resolve_quarantine` must already be seeded '
            .'(RolesAndPermissionsSeeder.php:465). If it were absent, ES-17 would owe an owner ruling on a new '
            .'permission + role seeder + permission:cache-reset, not an invented gate.',
        );

        $this->assertTrue(
            $this->resolver->can('fiscal.events.resolve_quarantine'),
            '17-E STOP CONDITION: the intended principal must be able to hold the EXISTING permission. '
            .'No new permission, no reseed, no permission:cache-reset is introduced by ES-17 (F17-6).',
        );
    }

    // =================================================================
    // 17-A — "An authorised operator can stamp `resolved_at` + `resolved_by`
    // on a quarantine row, and both land together. … Both columns non-null in
    // the same write, or neither — a half-stamped row is an incident state
    // nothing describes."
    // =================================================================

    public function test_an_authorised_operator_stamps_both_resolution_columns_together(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        $before = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($before);
        $this->assertNull($before->resolved_at, 'Pre-state: the incident is unresolved — that is the permanently-red state ES-17 exists to exit.');
        $this->assertNull($before->resolved_by);

        Sanctum::actingAs($this->resolver);
        $response = $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident");

        $response->assertOk();
        $response->assertJsonPath('data.id', $quarantineId);

        $after = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($after);
        $this->assertNotNull($after->resolved_at, '17-A: `resolved_at` must be stamped.');
        $this->assertSame(
            $this->resolver->id,
            (string) $after->resolved_by,
            '17-A: `resolved_by` must carry the adjudicating operator. Note (F-3): the NF525 §8 export publishes '
            .'`resolved_at` ONLY — `Nf525DataProvider::buildQuarantineSection()` never selects or emits `resolved_by` — '
            .'so this column is the ONLY place the adjudicator’s identity survives.',
        );
    }

    // =================================================================
    // M3b round 1, F-4 — the adjudication write had NO observability: no log
    // line, and the endpoint captured no reason, while its strictly LESS
    // consequential read-only sibling logs
    // `fiscal.quarantine.best_effort_parse_invoked`. Combined with F-3 (the
    // NF525 §8 export publishes `resolved_at` but NOT `resolved_by`), "who
    // cleared this incident, and why" existed nowhere an operator could read.
    // The log line is within contract; a reason COLUMN would be a schema
    // change and is deliberately NOT taken.
    // =================================================================

    public function test_the_adjudication_write_emits_a_log_line_carrying_the_actor_the_incident_and_the_reason(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        Log::spy();

        Sanctum::actingAs($this->resolver);
        $this->postJson(
            "/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident",
            ['reason' => 'Device clock skew confirmed with the operator; duplicate slot claim is benign.'],
        )->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($quarantineId): bool {
                return $message === 'fiscal.quarantine.incident_resolved'
                    && $context['quarantine_id'] === $quarantineId
                    && $context['tenant_id'] === $this->tenantId
                    && $context['user_id'] === $this->resolver->id
                    && $context['terminal_id'] === $this->terminalId
                    && $context['chain_context'] === 'operational'
                    && $context['claimed_sequence_number'] === 2
                    && $context['integrity_exception_class'] === IntegrityExceptionClass::SequenceConflict->value
                    && $context['reason_supplied'] === true
                    && is_string($context['reason'])
                    && str_contains($context['reason'], 'Device clock skew confirmed');
            });
    }

    public function test_a_refused_restamp_emits_no_second_adjudication_log_line(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        Sanctum::actingAs($this->resolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertOk();

        // Only the SECOND attempt is observed, so the assertion cannot be
        // satisfied by the first (legitimate) adjudication's line.
        Log::spy();

        $secondResolver = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Second resolver']);
        UserCompanyMembership::create([
            'user_id' => $secondResolver->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);
        $secondResolver->givePermissionTo('fiscal.events.resolve_quarantine');

        Sanctum::actingAs($secondResolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertStatus(409);

        Log::shouldNotHaveReceived('info', ['fiscal.quarantine.incident_resolved']);
    }

    public function test_the_response_does_not_present_the_stamp_as_a_correctness_claim(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        Sanctum::actingAs($this->resolver);
        $response = $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident");
        // Without this the assertions below pass vacuously against a 404 body.
        $response->assertOk();
        $body = (string) $response->getContent();

        foreach (['verified', 'accepted', 'valid', 'correct', 'approved'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                sprintf(
                    'F17-2: the stamp must not be presented as a correctness claim about the envelope. `sequence_conflict` means two '
                    .'different events claimed one slot; recording that a human looked at it says nothing about which was right. Found "%s".',
                    $forbidden,
                ),
            );
        }
    }

    // =================================================================
    // 17-B — "The stamp is EXPLICIT LIFECYCLE CODE, never mass assignment.
    // Asserted against `FiscalEventQuarantine.php:84-90`, which deliberately
    // keeps both columns out of `$fillable`. An implementation that adds them
    // to `$fillable` violates the model's stated boundary discipline and fails
    // this clause."  (F17-3.)
    // =================================================================

    public function test_resolution_columns_stay_out_of_fillable_and_resist_mass_assignment(): void
    {
        $fillable = (new FiscalEventQuarantine)->getFillable();

        $this->assertNotContains('resolved_at', $fillable, 'F17-3: `resolved_at` must never become mass-assignable.');
        $this->assertNotContains('resolved_by', $fillable, 'F17-3: `resolved_by` must never become mass-assignable.');

        // And the boundary actually holds at runtime, not just on paper.
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);
        $model = FiscalEventQuarantine::query()->findOrFail($quarantineId);
        $model->fill(['resolved_at' => Carbon::now('UTC'), 'resolved_by' => $this->resolver->id]);
        $model->save();

        $row = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->resolved_at, 'F17-3: a `fill()` of the lifecycle columns must be silently dropped by $fillable, not persisted.');
        $this->assertNull($row->resolved_by);
    }

    // =================================================================
    // 17-C — "After the stamp, `fiscal:verify-event-chain` stops reporting
    // that row. End-to-end: the command exits **1** with the quarantine
    // incident before, and **0** after (all other checks clean), on the same
    // fixture. This is the clause that closes the permanently-red state; the
    // `whereNull('resolved_at')` predicate at
    // `VerifyEventChainCommand.php:856` is the seam."
    //
    // CITATION CORRECTION (M3b round 1, F-1): the contract's `:856` is stale —
    // the predicate lives in `VerifyEventChainCommand::reportQuarantineIncidents()`,
    // and it is cited by SYMBOL from here on because that file's line numbers
    // have drifted three times in this wave.
    //
    // NOTE on the seam, per the contract's own finding: it has no writer.
    // ES-17 supplies the writer and NOTHING ELSE at that seam — the predicate
    // itself is untouched. F17-4 makes "demonstrate 17-C by teaching the
    // verifier to ignore quarantine rows" a rejection trigger, and it would
    // delete a control this wave just finished hardening.
    // =================================================================

    public function test_verifier_exits_one_before_the_stamp_and_zero_after_on_the_same_fixture(): void
    {
        $this->seedValidChain(3);
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        $before = $this->runVerifier();
        $this->assertSame(
            1,
            $before['exitCode'],
            'Pre-state: an unresolved quarantine incident makes the chain verifier non-green.',
        );
        $this->assertStringContainsString('QUARANTINE INCIDENT', $before['output']);
        $this->assertStringContainsString('claimed_sequence_number 2', $before['output']);

        Sanctum::actingAs($this->resolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertOk();

        $after = $this->runVerifier();
        $this->assertSame(
            0,
            $after['exitCode'],
            '17-C: this is the clause that closes the permanently-red state. Without a writer for `resolved_at`, '
            .'one sequence_conflict envelope made fiscal:verify-event-chain return exit 1 for that terminal FOREVER. '
            .'Output was: '.$after['output'],
        );
        $this->assertStringNotContainsString('QUARANTINE INCIDENT', $after['output']);
        $this->assertStringContainsString('chain verified', $after['output']);
    }

    public function test_an_unresolved_sibling_incident_still_fails_the_verifier_after_one_is_stamped(): void
    {
        // F17-4's positive control: the verifier must still be WATCHING. If
        // 17-C had been bought by making the verifier ignore quarantine rows,
        // this would go green too.
        $this->seedValidChain(3);
        $resolvedId = $this->seedQuarantineConflict(claimedSequence: 2);
        $this->seedQuarantineConflict(claimedSequence: 3);

        Sanctum::actingAs($this->resolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$resolvedId}/resolve-incident")->assertOk();

        $after = $this->runVerifier();
        $this->assertSame(1, $after['exitCode'], 'F17-4: stamping ONE incident must not blind the verifier to the others.');
        $this->assertStringContainsString('claimed_sequence_number 3', $after['output']);
        $this->assertStringNotContainsString('claimed_sequence_number 2', $after['output']);
    }

    // =================================================================
    // 17-D — "The stamp changes NOTHING ELSE. The quarantined envelope is NOT
    // copied into `fiscal_events`; `canonical_bytes`, `current_hash`,
    // `claimed_sequence_number`, `raw_envelope` and both classification
    // columns are byte-identical before and after; the conflicting event that
    // occupies the slot is untouched. Asserted column by column."  (F17-1.)
    // =================================================================

    public function test_the_stamp_changes_nothing_else_on_the_row_or_the_chain(): void
    {
        $this->seedValidChain(3);
        $conflictingEventId = (string) DB::table('fiscal_events')
            ->where('tenant_id', $this->tenantId)
            ->where('sequence_number', 2)
            ->value('id');
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2, conflictingEventId: $conflictingEventId);

        $columns = [
            'canonical_bytes', 'current_hash', 'previous_hash', 'claimed_sequence_number', 'raw_envelope',
            'integrity_exception_class', 'integrity_exception_reason', 'envelope_event_id', 'conflicting_event_id',
            'chain_context', 'event_type', 'tenant_id', 'company_id', 'terminal_id',
        ];

        $before = (array) DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first($columns);
        $fiscalEventsBefore = (array) DB::table('fiscal_events')
            ->where('id', $conflictingEventId)
            ->first(['canonical_bytes', 'current_hash', 'previous_hash', 'sequence_number', 'integrity_status']);
        $fiscalEventCountBefore = DB::table('fiscal_events')->where('tenant_id', $this->tenantId)->count();

        Sanctum::actingAs($this->resolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertOk();

        $after = (array) DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first($columns);
        foreach ($columns as $column) {
            $this->assertSame(
                $this->stringifyColumn($before[$column]),
                $this->stringifyColumn($after[$column]),
                sprintf('17-D: `%s` must be byte-identical across the stamp. The stamp adjudicates; it does not edit the envelope.', $column),
            );
        }

        $this->assertSame(
            $fiscalEventCountBefore,
            DB::table('fiscal_events')->where('tenant_id', $this->tenantId)->count(),
            'F17-1: the resolution must NOT admit the quarantined envelope into fiscal_events. That is a chain write, and it would '
            .'need a sequence slot that is by definition already occupied. ES-17 is adjudication, not ingestion.',
        );

        $fiscalEventsAfter = (array) DB::table('fiscal_events')
            ->where('id', $conflictingEventId)
            ->first(['canonical_bytes', 'current_hash', 'previous_hash', 'sequence_number', 'integrity_status']);
        foreach ($fiscalEventsBefore as $column => $value) {
            $this->assertSame(
                $this->stringifyColumn($value),
                $this->stringifyColumn($fiscalEventsAfter[$column]),
                sprintf('17-D: the conflicting event occupying the slot must be untouched — `%s` changed.', $column),
            );
        }
    }

    // =================================================================
    // 17-E, SECOND HALF — "An unauthorised principal is refused AND PERSISTS
    // NOTHING (row unstamped after the refused call)."  F17-5 makes a
    // status-only assertion insufficient: "A 403 that still stamped the row is
    // a failed contract."
    // =================================================================

    public function test_an_unauthorised_principal_is_refused_and_persists_nothing(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        $unauthorised = User::factory()->create(['tenant_id' => $this->tenantId]);
        UserCompanyMembership::create([
            'user_id' => $unauthorised->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($unauthorised);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertStatus(403);

        $row = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->resolved_at, 'F17-5: a 403 that still stamped the row is a failed contract. The refusal is two-sided.');
        $this->assertNull($row->resolved_by);
    }

    // =================================================================
    // 17-F — "Tenant-scoped. A resolver in tenant A cannot stamp tenant B's
    // quarantine row. Asserted, not assumed."
    // =================================================================

    public function test_a_resolver_cannot_stamp_another_tenants_quarantine_row(): void
    {
        $ownQuarantineId = $this->seedQuarantineConflict(claimedSequence: 2);
        $foreignQuarantineId = $this->seedForeignTenantQuarantineConflict();

        Sanctum::actingAs($this->resolver);

        // Positive control FIRST: without it, the 404 below is satisfied just
        // as well by "the endpoint does not exist" as by "the tenant filter
        // held" — which is exactly how a tenant-scoping test passes vacuously.
        $this->postJson("/api/v1/fiscal/quarantine/{$ownQuarantineId}/resolve-incident")->assertOk();

        $this->postJson("/api/v1/fiscal/quarantine/{$foreignQuarantineId}/resolve-incident")->assertStatus(404);

        $row = DB::table('fiscal_event_quarantine')->where('id', $foreignQuarantineId)->first();
        $this->assertNotNull($row);
        $this->assertNull(
            $row->resolved_at,
            '17-F: a cross-tenant stamp would let one tenant clear another tenant’s fiscal chain incident — and would publish a '
            .'FALSE adjudication timestamp into that tenant’s NF525 §8 export (the export carries `resolved_at`; per F-3 it '
            .'does NOT carry `resolved_by`, so the wrong operator’s id would be invisible there and survive only in the raw column).',
        );
        $this->assertNull($row->resolved_by);
    }

    // =================================================================
    // 17-G — "Idempotent / non-destructive on an already-resolved row.
    // Re-stamping either no-ops or refuses; it must not silently overwrite the
    // original `resolved_by`, which is the audit fact the §8 export publishes
    // (`Nf525DataProvider.php:1603-1605`)."
    //
    // CONTRACT ERRATUM (M3b round 1, F-3): the quoted rationale is wrong on its
    // facts. `Nf525DataProvider::buildQuarantineSection()` selects and emits
    // `resolved_at` only; `resolved_by` appears nowhere in that provider. The
    // CLAUSE stands unchanged — refuse, never overwrite — but the reason is
    // STRONGER than the contract believed: the adjudicator's identity is NOT
    // recoverable from the §8 export, so the raw column is its only home.
    // =================================================================

    public function test_restamping_an_already_resolved_row_refuses_and_preserves_the_original_stamp(): void
    {
        $quarantineId = $this->seedQuarantineConflict(claimedSequence: 2);

        Sanctum::actingAs($this->resolver);
        $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident")->assertOk();

        $first = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($first);

        $secondResolver = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Second resolver']);
        UserCompanyMembership::create([
            'user_id' => $secondResolver->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);
        $secondResolver->givePermissionTo('fiscal.events.resolve_quarantine');

        Sanctum::actingAs($secondResolver);
        $response = $this->postJson("/api/v1/fiscal/quarantine/{$quarantineId}/resolve-incident");
        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'QUARANTINE_ALREADY_RESOLVED');

        $second = DB::table('fiscal_event_quarantine')->where('id', $quarantineId)->first();
        $this->assertNotNull($second);
        $this->assertSame(
            (string) $first->resolved_by,
            (string) $second->resolved_by,
            '17-G: the original adjudicator must survive. Per F-3 the NF525 §8 export publishes `resolved_at` only, so '
            .'`resolved_by` is the ONLY record of who answered for the incident — silently overwriting it destroys that fact '
            .'with no export to recover it from.',
        );
        $this->assertSame($this->stringifyColumn($first->resolved_at), $this->stringifyColumn($second->resolved_at));
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @return array{exitCode: int, output: string}
     */
    private function runVerifier(): array
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('fiscal:verify-event-chain', [
            '--tenant' => $this->tenantId,
            '--terminal' => $this->terminalId,
            '--actor-id' => $this->verifierUser->id,
        ]);

        return ['exitCode' => $exitCode, 'output' => Artisan::output()];
    }

    private function seedValidChain(int $length): void
    {
        $previousHash = $this->genesisSeed;
        for ($seq = 1; $seq <= $length; $seq++) {
            $canonicalBytes = $this->canonicalEnvelope($seq, $previousHash);
            $currentHash = hash('sha256', $canonicalBytes);
            $this->insertEvent($seq, $canonicalBytes, $previousHash, $currentHash);
            $previousHash = $currentHash;
        }
    }

    private function canonicalEnvelope(int $sequenceNumber, string $previousHash): string
    {
        $fields = [
            'business_date' => '2026-08-12',
            'chain_context' => 'operational',
            'company_id' => $this->companyId,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'event_type' => FiscalEventType::CHAIN_BREAK_DETECTED->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => [
                'last_good_hash' => str_repeat('b', 64),
                'last_good_sequence' => 0,
                'offending_record_reference' => [
                    'observed_previous_hash' => $this->genesisSeed,
                    'sequence_number' => 1,
                    'terminal_id' => $this->terminalId,
                ],
                'reason' => 'ES-17 fixture '.$sequenceNumber,
            ],
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];
        ksort($fields);

        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function insertEvent(int $sequenceNumber, string $canonicalBytes, string $previousHash, string $currentHash): void
    {
        $now = Carbon::now('UTC');
        DB::table('fiscal_events')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::CHAIN_BREAK_DETECTED->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => '2026-08-12T07:00:00Z',
            'business_date' => '2026-08-12',
            'chain_context' => 'operational',
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
            'signature_status' => 'not_required',
            'integrity_status' => 'verified',
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => null,
            'payload_parse_status' => 'pending',
            'created_at' => $now,
        ]);
    }

    /**
     * Mirrors `VerifyEventChainCommandTest::seedQuarantineConflict()` — the
     * classification columns are set through `forceFill()` because the model
     * deliberately keeps them out of `$fillable` (the same Task 9 boundary
     * discipline 17-B pins for the lifecycle columns).
     */
    private function seedQuarantineConflict(int $claimedSequence, ?string $conflictingEventId = null): string
    {
        return $this->insertQuarantineRow(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            terminalId: $this->terminalId,
            operatorId: $this->operatorId,
            claimedSequence: $claimedSequence,
            conflictingEventId: $conflictingEventId ?? Str::uuid()->toString(),
        );
    }

    private function seedForeignTenantQuarantineConflict(): string
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        return $this->insertQuarantineRow(
            tenantId: $otherTenant->id,
            companyId: $otherCompany->id,
            terminalId: $otherTerminal->id,
            operatorId: Str::uuid()->toString(),
            claimedSequence: 2,
            conflictingEventId: Str::uuid()->toString(),
        );
    }

    private function insertQuarantineRow(
        string $tenantId,
        string $companyId,
        string $terminalId,
        string $operatorId,
        int $claimedSequence,
        string $conflictingEventId,
    ): string {
        $canonicalBytes = '{"event":"conflict_envelope","claimed_sequence_number":'.$claimedSequence.'}';
        $envelopeEventId = Str::uuid()->toString();
        $id = Str::uuid()->toString();

        $quarantine = (new FiscalEventQuarantine)->forceFill([
            'id' => $id,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $operatorId,
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
            'integrity_exception_class' => IntegrityExceptionClass::SequenceConflict->value,
            'integrity_exception_reason' => 'sequence_conflict:different_event_at_occupied_slot',
        ]);
        $quarantine->save();

        return $id;
    }

    /**
     * `canonical_bytes` comes back as a stream resource on PG (`bytea`), so a
     * naive `assertSame` would compare resource handles, not bytes — and would
     * pass while the bytes changed underneath. Normalise to a string first.
     */
    private function stringifyColumn(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return $value === null ? '' : (string) $value;
    }
}
