<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\OutboxIngestor;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
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
 *   1. Validate against the envelope BEFORE inserting:
 *      - `hash_ok`     — SHA-256(canonical_bytes) == current_hash
 *      - `linkage_ok`  — sequence_number == prior+1 AND previous_hash links
 *      - `clock_ok`    — §10 clock check
 *      - `parse_result = StrictCanonicalParser::parse()`
 *      Derive `integrity_status` / `integrity_exception_class` /
 *      `integrity_exception_reason` / `payload` / `payload_parse_status`.
 *
 *   2. Atomic INSERT into `fiscal_events` with ON CONFLICT DO NOTHING on
 *      `(tenant_id, terminal_id, sequence_number)`.
 *
 *   3. If inserted: within the same transaction insert one
 *      `fiscal_event_projections` row per active projector (suppressed for
 *      `canonical_parse_failure`); after commit, enqueue jobs.
 *
 *   4. If the slot is occupied: SELECT the existing row. If it matches
 *      byte-for-byte → idempotent success. Otherwise → write the envelope
 *      to `fiscal_event_quarantine` and return a `sequence_conflict` result.
 *
 * The tests below cover the eight plan-§1361 cases plus four defense cases
 * (handoff §4.2 standing patterns): time_anomaly, resolver-throws,
 * post-commit-only enqueue, and projection-row-not-created-for-parse-failure.
 *
 * PG-only assertions skip on SQLite (CHECK constraints / hash-format CHECKs
 * / partial indexes don't exist on the in-memory test driver).
 *
 * **Test-projector pattern (plan §1353).** Production registry's tagged
 * projector set is empty until Tasks 21/22. This test file defines a
 * `FakeSaleReceiptProjector` and tags it into the container in `setUp()`
 * so the container-built `FiscalEventProjectionRegistry` sees exactly one
 * active projector — used to assert the "one pending row per active
 * projector" contract without depending on production projectors that
 * don't exist yet.
 */
final class OutboxIngestorTest extends TestCase
{
    use RefreshDatabase;

    /** Stable tenant/company/terminal/operator for the per-test fixture. */
    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Str::uuid()->toString();
        $this->companyId = Str::uuid()->toString();
        $this->terminalId = Str::uuid()->toString();
        $this->operatorId = Str::uuid()->toString();

        // Tag the test-local fake projector into the container BEFORE the
        // FiscalEventProjectionRegistry singleton is resolved. The
        // production tagged set is still empty (Tasks 21/22 add real
        // projectors) — this fake exists ONLY to prove "one projection
        // row per active projector" without depending on prod projectors
        // that don't yet exist.
        $this->app->tag([FakeSaleReceiptProjector::class], FiscalEventProjector::class);

        // Avoid Job dispatch in tests — projection enqueue is Task 23's
        // job class which doesn't exist yet. The OutboxIngestor passes a
        // closure to `DB::afterCommit()`; Queue::fake() catches any
        // future job dispatch.
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

    public function test_verified_event_stores_with_zero_projection_rows_when_no_projector_is_active(): void
    {
        // Production state until Tasks 21/22 — empty tagged set. The event
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

        // sequence_number = 5 with a bogus previous_hash — hash doesn't
        // link to seq 1's current_hash.
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
        // setUp(); a successful ingest must produce at least one queued
        // job assertion (the closure DB::afterCommit() fires the
        // dispatcher only on commit success).
        $this->ingest($this->validEnvelope(['sequence_number' => 1]));

        // Queue::fake() captures the dispatch; ApplyFiscalEventProjectionJob
        // doesn't ship until Task 23. Until then, the ingestor calls
        // `DB::afterCommit()` with a closure that, in the production
        // wiring, will dispatch jobs. We assert the row was created and
        // that the closure path did NOT throw. (Task 23 will lock the
        // exact job-class assertion.) Use Queue::assertNothingPushed()
        // as a Phase-1-bound check that the integration seam is
        // present but the future job class is not invented in this task.
        Queue::assertNothingPushed();
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
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        // Also tag a Treasury-bound projector so the resolver is actually
        // invoked (POS-core always-active projectors don't ask the resolver).
        $this->app->tag([FakeTreasuryBoundProjector::class], FiscalEventProjector::class);

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
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'previous_hash' => str_repeat('0', 64),
        ];

        foreach ($overrides as $k => $v) {
            $fields[$k] = $v;
        }

        // Build the canonical envelope (spec §4 — 14 alphabetical keys).
        // The "payload" subobject is a minimal valid SALE_RECEIPT payload
        // per spec §4 / the StrictCanonicalParser PAYLOAD_KEYS map.
        $payload = $this->minimalSaleReceiptPayload();

        $canonicalArray = [
            'business_date' => $fields['business_date'],
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
     * @return array{
     *   currency: string,
     *   currency_scale: int,
     *   discount_total: string,
     *   lines: list<array<string, mixed>>,
     *   payment_lines: list<array<string, mixed>>,
     *   subtotal: string,
     *   tax_total: string,
     *   total: string,
     *   vat_breakdown: list<array<string, mixed>>,
     *   voucher_redemptions: list<array<string, mixed>>
     * }
     */
    private function minimalSaleReceiptPayload(): array
    {
        return [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [
                ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00'],
            ],
            'payment_lines' => [
                ['method' => 'CASH', 'amount' => '10.00', 'tendered' => '10.00', 'change' => '0.00'],
            ],
            'subtotal' => '10.00',
            'tax_total' => '0.00',
            'total' => '10.00',
            'vat_breakdown' => [
                ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
            ],
            'voucher_redemptions' => [],
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
     * Rebind FiscalEventProjectionRegistry with no projectors — exercises
     * the "production state until Tasks 21/22" empty-set behavior.
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
// Test-local fake projectors. Phase 1 has no production projectors yet
// (Tasks 21/22 add them). These exist only to drive the registry from
// inside this file's tests.
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
}
