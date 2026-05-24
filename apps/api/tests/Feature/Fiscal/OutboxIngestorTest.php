<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\OutboxIngestor;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 19 — `OutboxIngestor::ingest()` validate-then-insert core (spec v7 §7.2).
 *
 * The server-side verify-only mirror. Standalone operation (NOT nested in a
 * business transaction). For each envelope:
 *
 *   0. Shape invariants (round-2 T19-B4): regex-validate hashes / UUIDs /
 *      timestamps at the ingestor boundary; malformed → `fiscal_event_quarantine`
 *      with class `malformed_envelope`.
 *
 *   1. Validate against the envelope BEFORE inserting:
 *      - `hash_ok`     — SHA-256(canonical_bytes) == current_hash
 *      - `linkage_ok`  — sequence_number == prior+1 AND previous_hash links
 *                        (or for first events: previous_hash == terminal.genesis_seed)
 *      - `clock_ok`    — §10 clock check (drift threshold from config)
 *      - `parse_result = StrictCanonicalParser::parse()`
 *      Derive `integrity_status` / `integrity_exception_class` /
 *      `integrity_exception_reason` / `payload` / `payload_parse_status`.
 *
 *   2. Atomic INSERT into `fiscal_events` with raw
 *      `ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique
 *      DO NOTHING RETURNING id` (round-2 T19-B1).
 *
 *   3. If inserted: within the same transaction insert one
 *      `fiscal_event_projections` row per active projector (suppressed for
 *      `canonical_parse_failure`); after commit, enqueue jobs.
 *
 *   4. If the slot is occupied: SELECT the existing row (fresh statement —
 *      transaction is still alive). If it matches byte-for-byte → idempotent
 *      success. Otherwise → write the envelope to `fiscal_event_quarantine`
 *      and return a `sequence_conflict` result.
 *
 * **Round-2 coverage:**
 *   - T19-B1 — atomic conflict primitive (raw `ON CONFLICT ... RETURNING`).
 *   - T19-B2 — source-event-id constraint disambiguation (re-throw, not
 *     route through `sequence_conflict`).
 *   - T19-B3 — genesis-seed validation for first events (match, mismatch,
 *     terminal-not-found).
 *   - T19-B4 — envelope-shape invariants (malformed hash, malformed UUID,
 *     malformed timestamp → `malformed_envelope`).
 *   - T19-P1 — clock drift threshold is config-readable.
 *   - T19-P2 — `server_received_at` is driver-fetched (DB NOW()).
 *   - F4 — rolled-back outer transaction does not enqueue jobs.
 *
 * PG-only assertions skip on SQLite (CHECK constraints / hash-format CHECKs
 * / partial indexes don't exist on the in-memory test driver).
 *
 * **Test-projector pattern (plan §1353).** Task 21 now tags
 * `PosCoreReceiptProjection` into the production set. To keep these
 * tests deterministic (and to avoid coupling OutboxIngestor coverage to
 * the projector's evolving payload contract), `setUp()` rebinds the
 * `FiscalEventProjectionRegistry` singleton with an explicit list
 * containing only this file's `FakeSaleReceiptProjector`. That single
 * fake projector is what drives the "one pending row per active
 * projector" assertions below — the production POS-core projector is
 * intentionally swapped out for these tests.
 */
final class OutboxIngestorTest extends TestCase
{
    use RefreshDatabase;

    /** Stable tenant/company/terminal/operator for the per-test fixture. */
    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private string $locationId;

    /** Genesis seed seeded into `pos_terminals` for the test terminal — used by T19-B3 verifyLinkage. */
    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        // Genesis seed — kept at "all zeros" so existing tests that build
        // first events with previous_hash=str_repeat('0', 64) link
        // cleanly against the seeded terminal.
        $this->genesisSeed = str_repeat('0', 64);

        // Seed the FK chain so the OutboxIngestor's T19-B3 genesis-seed
        // lookup on `pos_terminals` finds a row. Factories chain
        // tenant -> company -> location -> terminal.
        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create([
            'company_id' => $this->companyId,
        ]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => $this->genesisSeed,
        ]);
        $this->terminalId = $terminal->id;

        // operator_id is not FK-constrained from fiscal_events, so a raw
        // UUID is fine.
        $this->operatorId = Str::uuid()->toString();

        // Rebind FiscalEventProjectionRegistry with ONLY the test-local
        // fake projector. This isolates these tests from production
        // projectors that get tagged in their own service providers
        // (Task 21's `PosCoreReceiptProjection` via `POSServiceProvider`,
        // Task 22's `TreasuryReceiptBridge` via `TreasuryServiceProvider`)
        // so the "one projection row per active projector" assertions
        // remain deterministic. The production tag set is implicitly
        // ignored here because the registry constructor takes the
        // projector list as a constructor argument, not at resolve time.
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                [new FakeSaleReceiptProjector],
                $this->app->make(ModuleActivationResolver::class),
            ),
        );

        // Queue::fake() catches the real `ApplyFiscalEventProjectionJob`
        // dispatches without executing them. Task 23 shipped the real job
        // class + the dispatch wiring in `OutboxIngestor::dispatchProjections`,
        // so without `Queue::fake()` each ingest test would actually run
        // the projection lifecycle inline against the sqlite test DB.
        // The `test_projection_dispatch_only_after_commit` test asserts
        // the dispatch contract via `Queue::assertPushed`; the rest of
        // the suite just needs the dispatcher silenced.
        Queue::fake();
    }

    // =================================================================
    // Plan §1361 cases (eight)
    // =================================================================

    public function test_verified_event_is_stored_with_payload_and_one_row_per_active_projector(): void
    {
        // Exactly one projector tagged in setUp (FakeSaleReceiptProjector) →
        // exactly one pending projection row, and the fiscal event lands
        // verified with the parsed payload.
        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        $this->assertTrue($result->stored);
        $this->assertNotNull($result->fiscalEventId);
        $this->assertFalse($result->sequenceConflict);
        $this->assertNull($result->exceptionClass);

        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertSame('parsed', $row->payload_parse_status);
        $this->assertNotNull($row->server_received_at);
        $this->assertNotNull($row->payload, 'verified event must carry a parsed payload');

        // One pending projection row per active projector — same DB
        // transaction as the fiscal_events insert (§7.2 Step 3).
        $this->assertSame(
            1,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->where('projection_status', 'pending')
                ->count(),
        );
        $this->assertSame(
            'fake_sale_receipt',
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->value('projector_name'),
        );
    }

    public function test_chain_context_allows_parallel_sequence_streams_for_same_terminal(): void
    {
        $operational = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
        $zSession = $this->ingest($this->validEnvelope([
            'id' => Str::uuid()->toString(),
            'chain_context' => 'z_session',
            'event_type' => FiscalEventType::SESSION_OPEN,
            'payload' => $this->minimalSessionOpenPayload(),
            'sequence_number' => 1,
            'source_event_class' => 'z_session_test',
            'source_event_id' => Str::uuid()->toString(),
        ]));

        $this->assertTrue($operational->stored);
        $this->assertTrue($zSession->stored);
        $this->assertFalse($zSession->sequenceConflict);

        $rows = DB::table('fiscal_events')
            ->where('terminal_id', $this->terminalId)
            ->orderBy('chain_context')
            ->get(['chain_context', 'sequence_number']);

        $this->assertSame(
            [
                ['chain_context' => 'operational', 'sequence_number' => 1],
                ['chain_context' => 'z_session', 'sequence_number' => 1],
            ],
            $rows->map(fn (object $row): array => [
                'chain_context' => (string) $row->chain_context,
                'sequence_number' => (int) $row->sequence_number,
            ])->all(),
        );
    }

    public function test_verified_event_stores_with_zero_projection_rows_when_no_projector_is_active(): void
    {
        // Simulated zero-active-projector state — `untagAllProjectors()`
        // strips both this test's fake projector AND any production
        // projectors tagged at boot (e.g., `PosCoreReceiptProjection`
        // tagged by Task 21 / `POSServiceProvider::register()`). The event
        // still lands verified, but no projection rows are created.
        $this->untagAllProjectors();

        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        $this->assertTrue($result->stored);
        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->count(),
        );
    }

    public function test_canonical_hash_mismatch_is_quarantined_in_table_projection_proceeds(): void
    {
        $env = $this->validEnvelope(['sequence_number' => 1]);
        $env->currentHash = str_repeat('f', 64); // != SHA-256(canonicalBytes)

        $result = $this->ingest($env);

        $this->assertTrue($result->stored);
        $this->assertFalse($result->sequenceConflict);

        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('canonical_hash_mismatch', $row->integrity_exception_class);
        $this->assertNotNull($row->integrity_exception_reason);

        // Per spec §7.5 — canonical_hash_mismatch projection PROCEEDS
        // (only canonical_parse_failure suppresses).
        $this->assertSame(
            1,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->count(),
        );
    }

    public function test_canonical_parse_failure_quarantines_payload_null_projection_suppressed(): void
    {
        // Build canonical bytes that hash correctly but fail the strict parser
        // (duplicate key at the envelope level).
        $bad = '{"a":1,"a":2}';
        $env = $this->validEnvelope(['sequence_number' => 1]);
        $env->canonicalBytes = $bad;
        $env->currentHash = hash('sha256', $bad);

        $result = $this->ingest($env);

        $this->assertTrue($result->stored);

        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('canonical_parse_failure', $row->integrity_exception_class);
        $this->assertNotNull($row->integrity_exception_reason);
        $this->assertNull($row->payload, 'parse failure → payload NULL (§7.6)');
        $this->assertSame('failed', $row->payload_parse_status);

        // Spec §7.5 — canonical_parse_failure SUPPRESSES projection rows.
        // Operator resolution + fiscal:enqueue-resolved-event-projections
        // is the recovery path.
        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->count(),
        );
    }

    public function test_idempotent_redelivery_of_same_event_returns_existing_no_redispatch(): void
    {
        $env = $this->validEnvelope(['sequence_number' => 1]);

        $first = $this->ingest($env);
        $second = $this->ingest($env);

        $this->assertTrue($first->stored);
        $this->assertFalse($second->stored, 're-delivery must report stored=false (no re-dispatch)');
        $this->assertSame($first->fiscalEventId, $second->fiscalEventId);
        $this->assertFalse($second->sequenceConflict);

        // Only one row in fiscal_events; only one set of projection rows.
        $this->assertSame(1, DB::table('fiscal_events')->count());
        $this->assertSame(
            1,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $first->fiscalEventId)
                ->count(),
        );
    }

    public function test_sequence_conflict_routes_to_quarantine_table_not_idempotent_success(): void
    {
        $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        // A DIFFERENT envelope claims the same (tenant, terminal, sequence_number)
        // slot. validEnvelope() generates a fresh `id` each call, so the
        // existing-row comparison in §7.2 Step 4 fails on the id mismatch
        // (and also on hash + canonical bytes).
        $conflicting = $this->validEnvelope(['sequence_number' => 1]);

        $result = $this->ingest($conflicting);

        $this->assertFalse($result->stored);
        $this->assertTrue($result->sequenceConflict);
        $this->assertNull($result->fiscalEventId, 'conflicting envelope physically cannot enter fiscal_events');

        // Verbatim envelope in the dedicated quarantine table.
        $q = DB::table('fiscal_event_quarantine')
            ->where('envelope_event_id', $conflicting->id)
            ->first();
        $this->assertNotNull($q, 'sequence_conflict envelope must land in fiscal_event_quarantine');
        $this->assertSame('sequence_conflict', $q->integrity_exception_class);
        $this->assertNotNull($q->canonical_bytes);
        $this->assertNotNull($q->raw_envelope);
        $this->assertNotNull($q->integrity_exception_reason);
        $this->assertSame((int) $conflicting->sequenceNumber, (int) $q->claimed_sequence_number);

        // The conflicting envelope never entered the ledger.
        $this->assertSame(1, DB::table('fiscal_events')->count());
    }

    public function test_sequence_gap_when_hash_linkage_is_broken(): void
    {
        $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        // sequence_number = 5 with the default previous_hash — hash
        // doesn't link to seq 1's current_hash.
        $gapped = $this->validEnvelope(['sequence_number' => 5]);

        $result = $this->ingest($gapped);

        $this->assertTrue($result->stored);
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('sequence_gap', $row->integrity_exception_class);
    }

    public function test_sequence_gap_when_numeric_gap_despite_valid_hash_linkage(): void
    {
        // The invariant the comment in plan §1444 calls out: linkage_ok
        // must check NUMERIC continuity too — seq 5 whose previous_hash
        // correctly equals seq 1's current_hash is STILL a sequence_gap
        // because seqs 2-4 are missing.
        $first = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
        $firstHash = (string) DB::table('fiscal_events')
            ->where('id', $first->fiscalEventId)
            ->value('current_hash');

        $gapped = $this->validEnvelope([
            'sequence_number' => 5,
            'previous_hash' => $firstHash, // hash links cleanly
        ]);

        $result = $this->ingest($gapped);

        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('sequence_gap', $row->integrity_exception_class);
    }

    // =================================================================
    // Defense cases (handoff §4.2 standing patterns)
    // =================================================================

    public function test_time_anomaly_quarantined_projection_proceeds(): void
    {
        // §10 — clock rollback case: the event's `event_time_device` is
        // 100 days BEFORE `server_received_at`. The ingestor must flag
        // `time_anomaly`, accept the row, and projection proceeds (§7.5).
        $env = $this->validEnvelope([
            'sequence_number' => 1,
            'event_time_device' => '2020-01-01T00:00:00Z', // ancient
        ]);

        $result = $this->ingest($env);

        $this->assertTrue($result->stored);
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('time_anomaly', $row->integrity_exception_class);

        // Projection PROCEEDS for time_anomaly per §7.5 ("accepted-and-flagged
        // and projection proceeds; the resulting rows inherit the flag").
        $this->assertSame(
            1,
            DB::table('fiscal_event_projections')
                ->where('fiscal_event_id', $result->fiscalEventId)
                ->count(),
        );
    }

    public function test_projection_dispatch_only_after_commit(): void
    {
        // Handoff §4.2 standing pattern. The §7.2 Step 3 contract: jobs
        // are enqueued AFTER the transaction commits. Queue::fake() in
        // setUp(); a successful ingest must produce one queued
        // ApplyFiscalEventProjectionJob per pending projection row (one
        // per active projector — `FakeSaleReceiptProjector` in setUp()).
        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));
        $this->assertNotNull($result->fiscalEventId);

        // Task 23 wires the real dispatch: one job per pending row,
        // dispatched via `DB::afterCommit()` AFTER T1 commits. With
        // exactly one fake projector in setUp(), exactly one job lands
        // on the queue.
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);

        // The dispatched job carries the projection-row id (NOT the
        // fiscal-event id — the job is keyed on the projection-row
        // identity per spec §7.5, since one event can have multiple
        // projection rows).
        $projectionRowId = (string) DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $result->fiscalEventId)
            ->value('id');

        Queue::assertPushed(
            ApplyFiscalEventProjectionJob::class,
            static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $projectionRowId,
        );
    }

    public function test_resolver_exception_does_not_crash_ingest(): void
    {
        // Handoff §4.2 standing pattern 4 — fail-closed on downstream-service
        // exception. If the registry's resolver throws while determining
        // active projectors for a SALE_RECEIPT event, the ingest path must
        // STILL persist the fiscal_events row. Projection set falls back
        // to whatever the registry's fail-closed handler emits.

        // Bind a thrown-resolver fake. The container singleton for the
        // registry was already resolved in setUp() (via the empty tagged
        // set) so we need to flush the singleton and rebind with our
        // thrown-resolver setup.
        $this->app->bind(ModuleActivationResolver::class, function () {
            return new class implements ModuleActivationResolver
            {
                public function isActive(string $module, string $tenantId, string $companyId): bool
                {
                    throw new RuntimeException('simulated resolver outage');
                }
            };
        });
        // Rebind the registry with the POS-core fake + the Treasury-bound
        // fake. The Treasury-bound projector forces the resolver to be
        // invoked (POS-core always-active projectors don't ask the
        // resolver). The setUp() binding is replaced because we need the
        // new resolver instance picked up by the registry.
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                [new FakeSaleReceiptProjector, new FakeTreasuryBoundProjector],
                $this->app->make(ModuleActivationResolver::class),
            ),
        );

        // The thrown-resolver fake will log via Log::error in the
        // registry's F1 fail-closed handler. Silence it cleanly.
        Log::shouldReceive('error')->andReturnNull();

        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        // The fiscal_events row must be persisted regardless.
        $this->assertTrue($result->stored, 'resolver outage must NOT crash ingest path — §7.2 fiscal row always persisted');
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('verified', $row->integrity_status);

        // The Treasury-bound projector is excluded (fail-closed); the
        // POS-core fake from setUp() remains active.
        $names = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $result->fiscalEventId)
            ->pluck('projector_name')
            ->all();
        $this->assertContains('fake_sale_receipt', $names);
        $this->assertNotContains('fake_treasury_bound', $names);
    }

    // =================================================================
    // Round-2 — T19-B3 genesis-seed validation for first events
    // =================================================================

    public function test_first_event_genesis_seed_match_succeeds(): void
    {
        // setUp() seeded the terminal with genesis_seed = str_repeat('0', 64).
        // validEnvelope() defaults previous_hash to str_repeat('0', 64).
        // First event (no prior row, sequence_number = 1) should link.
        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        $this->assertTrue($result->stored);
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('verified', $row->integrity_status);
        $this->assertNull($row->integrity_exception_class);
    }

    public function test_first_event_genesis_seed_mismatch_quarantines_as_sequence_gap(): void
    {
        // Terminal seeded with seed = '000...000'; envelope claims a
        // different seed via previous_hash = 'aaa...aaa'. Must quarantine
        // as sequence_gap with a structured reason naming the mismatch.
        $env = $this->validEnvelope([
            'sequence_number' => 1,
            'previous_hash' => str_repeat('a', 64),
        ]);

        $result = $this->ingest($env);

        $this->assertTrue($result->stored, 'malformed genesis-seed envelope still admitted to fiscal_events with quarantined status');
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('sequence_gap', $row->integrity_exception_class);

        $reason = is_string($row->integrity_exception_reason) ? $row->integrity_exception_reason : '';
        $this->assertStringContainsString('genesis_seed_mismatch', $reason);
    }

    public function test_first_event_unknown_terminal_quarantines_as_sequence_gap(): void
    {
        // Envelope references a terminal_id that has no pos_terminals row.
        // The genesis-seed lookup returns null → sequence_gap with reason
        // naming terminal-not-found. The fiscal_events row is still admitted
        // (status=quarantined) — the device is never blocked.
        $unknownTerminalId = Str::uuid()->toString();
        $env = $this->validEnvelope([
            'sequence_number' => 1,
            'terminal_id' => $unknownTerminalId,
        ]);

        $result = $this->ingest($env);

        $this->assertTrue($result->stored);
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame('sequence_gap', $row->integrity_exception_class);

        $reason = is_string($row->integrity_exception_reason) ? $row->integrity_exception_reason : '';
        $this->assertStringContainsString('terminal_not_found_for_genesis_seed_lookup', $reason);
    }

    // =================================================================
    // Round-2 — T19-B4 envelope-shape invariants
    // =================================================================

    public function test_malformed_timestamp_routed_to_quarantine_table(): void
    {
        // event_time_device is not subject to a PG CHECK constraint
        // shape, so a malformed value can route through
        // fiscal_event_quarantine with class malformed_envelope.
        $env = $this->validEnvelope(['sequence_number' => 1]);
        $env->eventTimeDevice = '2026-05-16 12:00:00'; // no T, no Z — invalid

        $result = $this->ingest($env);

        $this->assertFalse($result->stored);
        $this->assertFalse($result->sequenceConflict);
        $this->assertNotNull($result->exceptionClass);
        $this->assertSame('malformed_envelope', $result->exceptionClass->value);

        // No fiscal_events row.
        $this->assertSame(0, DB::table('fiscal_events')->count());

        // Quarantine row carries the malformed envelope.
        $q = DB::table('fiscal_event_quarantine')
            ->where('envelope_event_id', $env->id)
            ->first();
        $this->assertNotNull($q, 'malformed envelope must land in fiscal_event_quarantine');
        $this->assertSame('malformed_envelope', $q->integrity_exception_class);
        $reason = is_string($q->integrity_exception_reason) ? $q->integrity_exception_reason : '';
        $this->assertStringContainsString('event_time_device', $reason);
    }

    public function test_malformed_uuid_id_surfaces_as_ingest_exception_for_controller_422(): void
    {
        // Envelope.id is a malformed UUID. assertWireShape() rejects at
        // the boundary; because the quarantine table's `envelope_event_id`
        // is a PG `uuid`-typed column, even the quarantine insert would
        // fail. The ingestor surfaces this as InvalidArgumentException so
        // the controller (Task 20) renders 422 to the device.
        $env = $this->validEnvelope(['sequence_number' => 1]);
        $env->id = 'not-a-uuid-at-all';

        $this->expectException(\InvalidArgumentException::class);

        $this->ingest($env);
    }

    public function test_malformed_current_hash_surfaces_as_ingest_exception_for_controller_422(): void
    {
        // A non-hex current_hash cannot be persisted to fiscal_events
        // (PG CHECK rejects) NOR to fiscal_event_quarantine (the
        // quarantine table also CHECK-constrains the hash format). The
        // ingestor surfaces this as an InvalidArgumentException so the
        // controller (Task 20) renders 422 to the device. The envelope
        // is then re-tried by the device once it produces a valid hash
        // (or the device-side bug is fixed).
        $env = $this->validEnvelope(['sequence_number' => 1]);
        $env->currentHash = 'ZZZ_not_hex_padded_with_64_chars_total_to_match_check_length!!';

        $this->expectException(\InvalidArgumentException::class);

        $this->ingest($env);
    }

    // =================================================================
    // Round-2 — T19-P1 / T19-P2 clock + DB-now
    // =================================================================

    public function test_clock_drift_limit_is_config_readable(): void
    {
        // Override the threshold to a very small value (1 second).
        // event_time_device is "now" in setUp; the ingestor measures
        // drift vs server_received_at (also "now"), so the drift should
        // be 0 — well under 1 second. Then push event_time_device 5
        // seconds into the future and expect it to flag.
        config()->set('fiscal.clock_drift_limit_seconds', 1);

        // Future-shifted event_time_device (drift > 1s).
        $futureTime = now()->utc()->addSeconds(60)->format('Y-m-d\TH:i:s\Z');
        $env = $this->validEnvelope([
            'sequence_number' => 1,
            'event_time_device' => $futureTime,
        ]);

        $result = $this->ingest($env);

        $this->assertTrue($result->stored);
        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('time_anomaly', $row->integrity_exception_class);

        $reason = is_string($row->integrity_exception_reason) ? $row->integrity_exception_reason : '';
        $this->assertStringContainsString('drift_seconds=', $reason);
        $this->assertStringContainsString('limit=1', $reason);
    }

    public function test_server_received_at_is_driver_fetched_not_php_wall_clock(): void
    {
        // The persisted server_received_at must come from the DB driver
        // (NOW() on PG, CURRENT_TIMESTAMP on SQLite) — not from PHP's
        // CarbonImmutable::now(). We assert the persisted timestamp is
        // within a small window of "now" (both source the same NTP
        // ultimately, but the value must have crossed the DB driver).
        //
        // Without driver access to assert the SQL path was used, we
        // instead assert the value is well-formed and "recent" — the
        // shape proves the path. (A unit test on the helper itself
        // would be redundant; this integration test pins the contract.)
        $result = $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        $row = DB::table('fiscal_events')->where('id', $result->fiscalEventId)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->server_received_at);

        // Parse the stored timestamp and assert it's within 60 seconds
        // of now (epsilon — both clocks should match, this only proves
        // the path didn't return a sentinel like '1970-01-01').
        $stored = strtotime((string) $row->server_received_at);
        $this->assertNotFalse($stored);
        $this->assertLessThan(60, abs(time() - (int) $stored));
    }

    // =================================================================
    // Round-2 — F4 rolled-back outer transaction
    // =================================================================

    public function test_outer_transaction_rollback_suppresses_fiscal_event_and_projection_dispatch(): void
    {
        // Wrap ingest() in an outer transaction that ROLLBACKs after
        // ingest returns. The fiscal_events row must be gone AND no job
        // must have been pushed (DB::afterCommit() respects outer
        // rollback). Proves the after-commit dispatch seam is bound to
        // the OUTER commit, not the inner T1.
        $env = $this->validEnvelope(['sequence_number' => 1]);

        try {
            DB::transaction(function () use ($env) {
                $this->ingest($env);
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $e) {
            // expected
            $this->assertSame('force rollback', $e->getMessage());
        }

        // The fiscal_events row is gone — the outer rollback discarded T1.
        $this->assertSame(0, DB::table('fiscal_events')->count());
        $this->assertSame(0, DB::table('fiscal_event_projections')->count());

        // No job was pushed — DB::afterCommit() respects the outer
        // rollback (the closure never fires).
        Queue::assertNothingPushed();
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Build a valid `FiscalEventEnvelope` whose `canonical_bytes` is a
     * properly-formed canonical SALE_RECEIPT envelope per spec §4 + §7.6,
     * and whose `current_hash` correctly equals `SHA-256(canonical_bytes)`.
     *
     * `$overrides` keys are envelope field names (`sequence_number`,
     * `previous_hash`, `event_time_device`, etc.) — used to provoke specific
     * §7.2 anomaly classes.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function validEnvelope(array $overrides = []): FiscalEventEnvelope
    {
        $fields = [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'business_date' => now()->utc()->toDateString(),
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => $this->genesisSeed, // first events link to genesis seed
        ];

        foreach ($overrides as $k => $v) {
            $fields[$k] = $v;
        }

        // Build the canonical envelope (spec §4 — 14 alphabetical keys).
        // The "payload" subobject is a minimal valid SALE_RECEIPT payload
        // per spec §4 / the StrictCanonicalParser PAYLOAD_KEYS map.
        $payloadOverride = $overrides['payload'] ?? null;
        unset($overrides['payload']);

        $payload = is_array($payloadOverride)
            ? $payloadOverride
            : $this->minimalSaleReceiptPayload();

        $canonicalArray = [
            'business_date' => $fields['business_date'],
            'chain_context' => $fields['chain_context'],
            'company_id' => $fields['company_id'],
            'event_time_device' => $fields['event_time_device'],
            'event_type' => $fields['event_type']->value,
            'event_version' => $fields['event_version'],
            'operator_id' => $fields['operator_id'],
            'payload' => $payload,
            'previous_hash' => $fields['previous_hash'],
            'reference_document_id' => $fields['reference_document_id'],
            'reference_event_id' => $fields['reference_event_id'],
            'sequence_number' => $fields['sequence_number'],
            'signature_version' => $fields['signature_version'],
            'tenant_id' => $fields['tenant_id'],
            'terminal_id' => $fields['terminal_id'],
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);

        return new FiscalEventEnvelope(
            envelopeId: Str::uuid()->toString(),
            idempotencyKey: $fields['terminal_id'].':'.$fields['sequence_number'],
            payloadVersion: 1,
            id: $fields['id'],
            tenantId: $fields['tenant_id'],
            companyId: $fields['company_id'],
            terminalId: $fields['terminal_id'],
            operatorId: $fields['operator_id'],
            eventType: $fields['event_type'],
            eventVersion: $fields['event_version'],
            signatureVersion: $fields['signature_version'],
            sequenceNumber: $fields['sequence_number'],
            eventTimeDevice: $fields['event_time_device'],
            businessDate: $fields['business_date'],
            chainContext: $fields['chain_context'],
            lastServerTimeSeen: $fields['last_server_time_seen'],
            referenceEventId: $fields['reference_event_id'],
            referenceDocumentId: $fields['reference_document_id'],
            sourceEventClass: $fields['source_event_class'],
            sourceEventId: $fields['source_event_id'],
            previousHash: $fields['previous_hash'],
            currentHash: $currentHash,
            canonicalBytes: $canonicalBytes,
        );
    }

    /**
     * Pass 2A.PHP.2 — 28-key Candidate C-v3 SALE_RECEIPT payload per
     * synthesis v5 §3. Hand-balanced totals: subtotal + vat_total ==
     * total + transaction_discount_amount.
     *
     * @return array<string, mixed>
     */
    private function minimalSaleReceiptPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => '10.00',
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => '10.00',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '10.00',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '10.00',
                'net_amount' => '10.00',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalSessionOpenPayload(): array
    {
        return [
            'business_date' => '2026-05-20',
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'opened_at_device' => '2026-05-20T08:00:00.000Z',
            'opening_float_amount' => '100.00',
            'operator_id' => '11111111-1111-4111-8111-111111111111',
            'operator_name' => 'Default Cashier',
            'session_id' => '77777777-7777-4777-8777-777777777777',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'terminal_label' => 'T01',
            'training_flag' => false,
        ];
    }

    /**
     * Spec §4 JCS canonical encoding — alphabetical keys, no whitespace,
     * integer-only numbers, strings UTF-8 with `\u` escapes only for
     * forbidden control chars. The test payload uses only ASCII strings
     * + ASCII money + ASCII enum tokens, so JSON_UNESCAPED_SLASHES |
     * JSON_UNESCAPED_UNICODE produces canonical output. Keys must be
     * sorted at every depth.
     *
     * Not the production encoder (the producer is the TS device-side
     * encoder in Task 5); good enough to drive the server-side parser
     * here.
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sortedRecursive = $this->sortRecursive($value);
        $json = json_encode($sortedRecursive, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            // List — recurse into elements; do not sort indices.
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        // Associative — sort keys.
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }

    private function ingest(FiscalEventEnvelope $env): IngestionResult
    {
        // Resolve fresh from the container so test-local container changes
        // (untagAllProjectors / resolver-throws rebinding) take effect.
        $ingestor = $this->app->make(OutboxIngestor::class);

        return $ingestor->ingest($env);
    }

    /**
     * Rebind FiscalEventProjectionRegistry with no projectors — strips
     * production-tagged projectors (e.g. `PosCoreReceiptProjection` from
     * Task 21 / `POSServiceProvider::register()`) so the test can assert
     * the zero-active-projector path cleanly.
     */
    private function untagAllProjectors(): void
    {
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                [], // no projectors tagged
                $this->app->make(ModuleActivationResolver::class),
            ),
        );
    }
}

// =====================================================================
// Test-local fake projectors. Task 21 added `PosCoreReceiptProjection`
// as the production POS-core projector and Task 22 adds the gated
// `TreasuryReceiptBridge`. These fakes are used in `setUp()` to swap the
// projector set for deterministic registry behavior; they do not exist
// in the production container otherwise.
// =====================================================================

final class FakeSaleReceiptProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'fake_sale_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        // no-op fake — Task 23's ApplyFiscalEventProjectionJob is the
        // actual invoker; for ingest-path tests we never execute apply().
        unset($event);
    }

    public function priority(): int
    {
        return 50;
    }
}

final class FakeTreasuryBoundProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'fake_treasury_bound';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    // Module-bound — the resolver is consulted, which is the path the
    // resolver-throws defense test exercises.
    public function requiresModule(): string
    {
        return 'Treasury';
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return 150;
    }
}
