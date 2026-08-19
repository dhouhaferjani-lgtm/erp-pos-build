<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\Services\OutboxIngestor;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ES-16 — the `z_session_lifecycle` quarantine blind spot.
 *
 * Contract of record: `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`
 * at **revision 2** (amended in M3 fix round 1, ACCEPTed at M3 round 2). Clauses
 * **16-A … 16-F**, falsifiers **F16-1 … F16-7**. Quoted verbatim at each test.
 *
 * **The defect, as the contract states it.**
 * `OutboxIngestor::verifyZSessionLifecycle()` returns a `z_session_lifecycle:*`
 * verdict for seven lifecycle violations. That verdict is folded into
 * `$linkageVerdict` (`OutboxIngestor.php:182-186`), so `deriveIntegrity()`
 * classifies the row as **`sequence_gap`** — the `z_session_lifecycle:` string
 * survives only inside `integrity_exception_reason`. `dispatchProjections()`
 * then suppresses every projection for it (`OutboxIngestor.php:922-924`).
 * The row therefore lands in NEITHER partition of
 * `DeadLetteredProjectionsController::index()`: partition 1 needs a
 * dead-lettered `fiscal_event_projections` row (there are zero), and partition 2
 * needs `integrity_exception_class = 'canonical_parse_failure'` (the class is
 * `sequence_gap`). A suppressed `SESSION_CLOSE` / `Z_REPORT` means the day's Z
 * aggregates silently never project, with no operator-visible signal anywhere.
 *
 * **ES-16 is a PURE VISIBILITY contract.** Clause 16-C was rewritten in
 * revision 2 to require that the surfaced row name **no recovery command at
 * all**: `fiscal:enqueue-resolved-event-projections` applies no
 * `integrity_status` filter and `createMissingPendingRows()` re-checks no
 * suppression, so running it on such a row creates exactly the projections the
 * ingestor refused — F16-2 performed by the operator instead of by the code.
 * Nothing here routes anyone toward it.
 *
 * **Every fixture in this file is built through the REAL ingestion path.**
 * 16-A demands it, and F16-3 makes a hand-inserted row of a shape the ingestor
 * never produces (e.g. `canonical_parse_failure` carrying a
 * `z_session_lifecycle:` reason — impossible, since a lifecycle verdict requires
 * `$parseResult->ok`) an explicit rejection trigger. The only hand-inserted rows
 * in this file are the two EXISTING partitions' fixtures in the 16-E
 * co-existence test, where they are the control, not the subject.
 */
final class ZSessionLifecycleQuarantineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    /** Second terminal — lets 16-B seed two DIFFERENT violations without either one's sequence slot colliding. */
    private string $secondTerminalId;

    private string $operatorId;

    private string $genesisSeed;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('0', 64);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $this->terminalId = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => $this->genesisSeed,
        ])->id;

        $this->secondTerminalId = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => $this->genesisSeed,
        ])->id;

        $this->operatorId = Str::uuid()->toString();

        $this->operator = User::factory()->create(['tenant_id' => $this->tenantId]);
        UserCompanyMembership::create([
            'user_id' => $this->operator->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);
        $this->operator->givePermissionTo('fiscal.refunds.manage_dead_letters');

        // The production projector registry is deliberately NOT rebound: this
        // file's subject is what the REAL ingestion path produces. For a
        // lifecycle-quarantined row `dispatchProjections()` returns before the
        // registry is ever consulted, so the projection count is zero either
        // way — and asserting it against the real registry is the stronger
        // evidence. `Queue::fake()` only silences the dispatcher for the
        // hand-inserted control rows.
        Queue::fake();
    }

    // =================================================================
    // 16-A — "A `z_session_lifecycle` quarantine appears in
    // `DeadLetteredProjectionsController::index()`'s response."
    // Demonstration required: "Red-first: a test that seeds the row through
    // the real ingestion path (`OutboxIngestor`, one of the seven lifecycle
    // violations) and asserts the row's id appears in the endpoint's payload."
    // =================================================================

    public function test_index_surfaces_a_lifecycle_quarantine_produced_by_the_real_ingestor(): void
    {
        $eventId = $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);

        // First pin the blind spot's three premises ON THE REAL ROW, so this
        // test also proves the fixture is the shape the contract describes
        // (and not the F16-3 impossible shape).
        $row = DB::table('fiscal_events')->where('id', $eventId)->first();
        $this->assertNotNull($row, 'The ingestor must have stored the row — a lifecycle violation quarantines, it does not reject.');
        $this->assertSame('quarantined', $row->integrity_status);
        $this->assertSame(
            'sequence_gap',
            $row->integrity_exception_class,
            'Premise of the blind spot: the class is sequence_gap, NOT canonical_parse_failure — which is exactly why partition 2 skips it.',
        );
        $this->assertStringContainsString(
            'z_session_lifecycle:',
            is_string($row->integrity_exception_reason) ? $row->integrity_exception_reason : '',
            'The lifecycle verdict survives only inside integrity_exception_reason.',
        );
        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')->where('fiscal_event_id', $eventId)->count(),
            'Premise of the blind spot: OutboxIngestor.php:922-924 suppresses every projection, so partition 1 is empty for this row.',
        );

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $this->assertContains(
            $eventId,
            array_column($response->json('data'), 'fiscal_event_id'),
            'ES-16: a z_session_lifecycle quarantine is invisible to the operator on the dead-letter read surface. '
            .'A suppressed SESSION_CLOSE or Z_REPORT means the day’s Z aggregates silently never project, with no signal anywhere.',
        );
    }

    // =================================================================
    // 16-B — "The surfaced row names WHICH lifecycle violation fired."
    // Demonstration required: "The response carries the
    // `z_session_lifecycle:<reason>` discriminator, not just 'quarantined'.
    // Asserted for at least two *different* violations so the field is proven
    // to vary rather than being a constant."  (F16-4 is the hard-coded-string
    // trigger this kills.)
    // =================================================================

    public function test_index_names_which_lifecycle_violation_fired_and_the_value_varies(): void
    {
        $missingOpenId = $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);
        $sourceMismatchId = $this->ingestLifecycleViolationSessionOpenSourceMismatch($this->secondTerminalId);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $byId = $this->rowsKeyedByFiscalEventId($response->json('data'));

        $this->assertArrayHasKey($missingOpenId, $byId);
        $this->assertArrayHasKey($sourceMismatchId, $byId);

        $this->assertSame(
            'z_session_lifecycle:missing_session_open',
            $byId[$missingOpenId]['lifecycle_violation'] ?? null,
            'The operator must be told WHICH of the seven lifecycle rules fired, not merely that the row is quarantined.',
        );
        $this->assertSame(
            'z_session_lifecycle:session_open_source_mismatch',
            $byId[$sourceMismatchId]['lifecycle_violation'] ?? null,
            'F16-4: the discriminator must be READ from integrity_exception_reason. Two different violations must produce two different values.',
        );
        $this->assertNotSame(
            $byId[$missingOpenId]['lifecycle_violation'],
            $byId[$sourceMismatchId]['lifecycle_violation'],
            'F16-4: a constant would satisfy either assertion alone. It cannot satisfy both.',
        );

        // The full forensic reason is preserved too — the discriminator is an
        // extraction, never a replacement.
        $this->assertStringContainsString(
            'z_session_lifecycle:missing_session_open',
            (string) ($byId[$missingOpenId]['integrity_exception_reason'] ?? ''),
        );
    }

    // =================================================================
    // 16-C (amended, revision 2) — "The surfaced row names NO recovery
    // command at all. ES-16 is a pure visibility contract: it reports the row
    // and stops."  Demonstration required: "The response carries **no**
    // remediation/recovery/next-action field naming
    // `fiscal:enqueue-resolved-event-projections` or any other command …
    // Asserted as an absence, not assumed."
    // =================================================================

    public function test_index_row_for_a_lifecycle_quarantine_names_no_recovery_command(): void
    {
        $eventId = $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $byId = $this->rowsKeyedByFiscalEventId($response->json('data'));
        $this->assertArrayHasKey($eventId, $byId);
        $row = $byId[$eventId];

        // The two existing partitions each carry `write_off_action_url`. The
        // lifecycle partition must not: a write-off is not the action for a
        // lifecycle-invalid Z session, and 16-C forbids naming ANY action.
        $this->assertArrayNotHasKey(
            'write_off_action_url',
            $row,
            '16-C: the lifecycle row must carry no action affordance at all — not even the write-off URL the other two partitions surface.',
        );

        foreach (['remediation', 'recovery', 'next_action', 'action_url', 'resolve_url', 'command'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $row, sprintf('16-C: no next-action field. Found `%s`.', $forbiddenKey));
        }

        // Nothing anywhere in the body may name a command. This test seeds ONLY
        // the lifecycle row, so the body is exactly the surface under test.
        $body = (string) $response->getContent();
        foreach ([
            'enqueue-resolved-event-projections',
            'fiscal:enqueue',
            'artisan',
            'write_off',
            'best-effort-parse',
            'resolve-parse-failure',
        ] as $forbiddenToken) {
            $this->assertStringNotContainsString(
                $forbiddenToken,
                $body,
                sprintf(
                    'F16-7: the response must not route an operator toward `%s`. Running fiscal:enqueue-resolved-event-projections '
                    .'on a z_session_lifecycle row creates precisely the projections OutboxIngestor.php:922-924 refused — F16-2 by hand.',
                    $forbiddenToken,
                ),
            );
        }
    }

    // =================================================================
    // 16-E — "The existing two partitions keep their exact current contents.
    // The canonical-parse-failure partition and the dead-lettered-projection
    // partition are each pinned with an unchanged-behaviour test, so ES-16's
    // row is proven ADDITIVE and not a re-partitioning that quietly moves
    // other rows."  (F16-1 and F16-5 are the triggers this closes.)
    // =================================================================

    public function test_lifecycle_partition_is_additive_and_leaves_both_existing_partitions_exact(): void
    {
        $deadLetteredId = $this->insertControlFiscalEvent(canonicalParseFailure: false);
        $this->insertControlProjectionRow($deadLetteredId, 'pos_core_receipt', ProjectionStatus::DeadLettered);
        $parseFailureId = $this->insertControlFiscalEvent(canonicalParseFailure: true);
        $lifecycleId = $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(3, $rows, 'Exactly one row per partition — the lifecycle partition must ADD one row, not re-home the others.');

        $byId = $this->rowsKeyedByFiscalEventId($rows);

        // Partition 1 — unchanged, including the write-off affordance.
        $this->assertSame('dead_lettered_projection', $byId[$deadLetteredId]['source']);
        $this->assertSame('pos_core_receipt', $byId[$deadLetteredId]['projector_name']);
        $this->assertSame(route('fiscal.refund-compensations.store'), $byId[$deadLetteredId]['write_off_action_url']);

        // Partition 2 — unchanged. F16-1: the canonical_parse_failure predicate
        // must NOT have been weakened to "any quarantined class". If it had
        // been, the lifecycle row (class sequence_gap) would come back tagged
        // `ingress_quarantine` and this count would be 2.
        $this->assertSame('ingress_quarantine', $byId[$parseFailureId]['source']);
        $this->assertSame(route('fiscal.refund-compensations.store'), $byId[$parseFailureId]['write_off_action_url']);
        $this->assertCount(
            1,
            array_filter($rows, static fn (array $row): bool => $row['source'] === 'ingress_quarantine'),
            'F16-1: widening partition 2 would drag sequence_gap / canonical_hash_mismatch / time_anomaly rows into a surface built for parse failures.',
        );

        // The new partition is its own third class, keyed the same way.
        $this->assertNotSame($byId[$lifecycleId]['source'], $byId[$parseFailureId]['source']);
        $this->assertNotSame($byId[$lifecycleId]['source'], $byId[$deadLetteredId]['source']);
    }

    public function test_projector_filter_excludes_the_lifecycle_partition(): void
    {
        $deadLetteredId = $this->insertControlFiscalEvent(canonicalParseFailure: false);
        $this->insertControlProjectionRow($deadLetteredId, 'pos_core_receipt', ProjectionStatus::DeadLettered);
        $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections?projector=pos_core_receipt');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.fiscal_event_id', $deadLetteredId);
        // A lifecycle row has no projector_name at all — exactly the reason
        // partition 2 is also filter-excluded. The new partition must behave
        // the same way rather than inventing a third convention.
    }

    // =================================================================
    // 16-F — "The new partition is TENANT-SCOPED exactly like the existing
    // two. A second tenant's `z_session_lifecycle` row is absent from the
    // first tenant's response … asserted, not assumed."
    // =================================================================

    public function test_a_lifecycle_quarantine_from_another_tenant_is_absent(): void
    {
        $ownId = $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);
        $otherTenantEventId = $this->ingestLifecycleViolationInAForeignTenant();

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'fiscal_event_id');

        $this->assertContains($ownId, $ids);
        $this->assertNotContains(
            $otherTenantEventId,
            $ids,
            '16-F: the lifecycle partition owes the same tenant filter as the two it joins. A cross-tenant leak here exposes another '
            .'tenant’s fiscal terminal ids and Z-session coordinates.',
        );
    }

    public function test_index_denies_a_user_without_the_permission(): void
    {
        $this->ingestLifecycleViolationMissingSessionOpen($this->terminalId);

        $unauthorized = User::factory()->create(['tenant_id' => $this->tenantId]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->companyId,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($unauthorized);

        $this->getJson('/api/v1/fiscal/dead-lettered-projections')->assertStatus(403);
    }

    // =================================================================
    // Fixtures — all lifecycle rows go through the REAL OutboxIngestor.
    // =================================================================

    /**
     * Violation 1 of 7 — `missing_session_open`. A z_session cash movement
     * arriving with no SESSION_OPEN ahead of it. Mirrors
     * `OutboxIngestorTest::test_z_session_movement_without_session_open_is_quarantined`.
     */
    private function ingestLifecycleViolationMissingSessionOpen(string $terminalId): string
    {
        $result = $this->ingest($this->envelope([
            'terminal_id' => $terminalId,
            'chain_context' => 'z_session',
            'event_type' => FiscalEventType::CASH_IN,
            'payload' => $this->minimalCashMovementPayload(),
            'sequence_number' => 1,
        ]));

        return $this->assertIngestedLifecycleQuarantine($result, 'missing_session_open');
    }

    /**
     * Violation 2 of 7 — `session_open_source_mismatch`. A SESSION_OPEN whose
     * sealed `source_event_id` does not equal its payload's `session_id`.
     * Mirrors `OutboxIngestorTest::test_session_open_source_id_must_match_payload_session_id`.
     */
    private function ingestLifecycleViolationSessionOpenSourceMismatch(string $terminalId): string
    {
        $result = $this->ingest($this->envelope([
            'terminal_id' => $terminalId,
            'chain_context' => 'z_session',
            'event_type' => FiscalEventType::SESSION_OPEN,
            'payload' => $this->minimalSessionOpenPayload(),
            'sequence_number' => 1,
            'source_event_class' => 'pos_session',
            'source_event_id' => Str::uuid()->toString(),
        ]));

        return $this->assertIngestedLifecycleQuarantine($result, 'session_open_source_mismatch');
    }

    /**
     * A lifecycle quarantine belonging to a DIFFERENT tenant, with its own
     * company + location + terminal, driven through the same real ingestor.
     */
    private function ingestLifecycleViolationInAForeignTenant(): string
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

        $result = $this->ingest($this->envelope([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'terminal_id' => $otherTerminal->id,
            'chain_context' => 'z_session',
            'event_type' => FiscalEventType::CASH_IN,
            'payload' => $this->minimalCashMovementPayload(),
            'sequence_number' => 1,
        ]));

        return $this->assertIngestedLifecycleQuarantine($result, 'missing_session_open');
    }

    /**
     * Guard the fixture itself: if the ingestor ever stops producing the
     * shape ES-16 describes, these tests must fail LOUDLY at the fixture
     * rather than silently asserting against a row that is no longer the
     * subject (F16-3).
     */
    private function assertIngestedLifecycleQuarantine(IngestionResult $result, string $expectedViolation): string
    {
        $this->assertTrue($result->stored, 'Fixture guard: a lifecycle violation must be STORED as a quarantine, not rejected.');
        $eventId = $result->fiscalEventId;
        $this->assertIsString($eventId);

        $row = DB::table('fiscal_events')->where('id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertSame(
            'sequence_gap',
            $row->integrity_exception_class,
            'Fixture guard (F16-3): the real ingestor classifies these as sequence_gap. A fixture claiming any other class is not ES-16.',
        );
        $this->assertStringContainsString(
            'z_session_lifecycle:'.$expectedViolation,
            is_string($row->integrity_exception_reason) ? $row->integrity_exception_reason : '',
            'Fixture guard: this envelope must trip exactly the lifecycle rule the test names.',
        );

        return $eventId;
    }

    private function ingest(FiscalEventEnvelope $env): IngestionResult
    {
        return $this->app->make(OutboxIngestor::class)->ingest($env);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function envelope(array $overrides = []): FiscalEventEnvelope
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
            'previous_hash' => $this->genesisSeed,
        ];

        foreach ($overrides as $key => $value) {
            $fields[$key] = $value;
        }

        $payload = isset($overrides['payload']) && is_array($overrides['payload'])
            ? $overrides['payload']
            : $this->minimalCashMovementPayload();

        // Spec §4 canonical envelope — 15 alphabetical keys including
        // chain_context. Same shape OutboxIngestorTest builds.
        $canonicalBytes = $this->canonicalEncode([
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
        ]);

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
            currentHash: hash('sha256', $canonicalBytes),
            canonicalBytes: $canonicalBytes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalCashMovementPayload(): array
    {
        return [
            'amount' => '10.00',
            'approval' => null,
            'business_date' => '2026-05-20',
            'cash_drawer_operation_id' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T08:30:00.000Z',
            'movement_id' => '55555555-5555-4555-8555-555555555555',
            'movement_type' => 'CASH_IN',
            'operator_id' => '11111111-1111-4111-8111-111111111111',
            'operator_name' => 'Default Cashier',
            'reason_code' => 'cash_drawer_deposit',
            'reason_text' => null,
            'session_id' => '77777777-7777-4777-8777-777777777777',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'training_flag' => false,
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
            'shift_number' => 1,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'terminal_label' => 'T01',
            'training_flag' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
    }

    // ---- Controls for 16-E: the two EXISTING partitions' fixtures. ----

    private function insertControlFiscalEvent(bool $canonicalParseFailure): string
    {
        $payload = $canonicalParseFailure ? null : ['test' => 'existing-partition-control'];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operator->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(100000, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalParseFailure ? 'not valid json {' : json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => $canonicalParseFailure ? IntegrityStatus::Quarantined : IntegrityStatus::Verified,
            'integrity_exception_class' => $canonicalParseFailure ? 'canonical_parse_failure' : null,
            'integrity_exception_reason' => $canonicalParseFailure ? 'payload did not parse as JSON' : null,
            'payload' => $payload,
            'payload_parse_status' => $canonicalParseFailure ? PayloadParseStatus::Failed : PayloadParseStatus::Parsed,
        ])->id;
    }

    private function insertControlProjectionRow(string $fiscalEventId, string $projectorName, ProjectionStatus $status): void
    {
        $now = now();
        DB::table('fiscal_event_projections')->insert([
            'id' => Str::uuid()->toString(),
            'fiscal_event_id' => $fiscalEventId,
            'projector_name' => $projectorName,
            'projection_status' => $status->value,
            'attempts' => 5,
            'last_error' => 'RefundQuantityExceededException: over cap',
            'last_attempted_at' => $now,
            'dead_lettered_at' => $now,
            'applied_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsKeyedByFiscalEventId(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row['fiscal_event_id']] = $row;
        }

        return $keyed;
    }
}
