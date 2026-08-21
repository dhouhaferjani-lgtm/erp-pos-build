<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The server-side verify-only mirror — spec v7 §7.2.
 *
 * Receives a typed `FiscalEventEnvelope` from the wire (Task 20's controller
 * builds it), validates it against the fiscal-event invariants, inserts into
 * `fiscal_events`, dispatches projection rows, and routes
 * sequence-conflicting envelopes to `fiscal_event_quarantine`.
 *
 * **Standalone operation, NOT nested in a business transaction.** The
 * ingestor opens its own transaction (T1) just for the
 * `fiscal_events` INSERT + same-transaction `fiscal_event_projections`
 * row creation; jobs are enqueued AFTER T1 commits via
 * `DB::afterCommit()` — preventing the "row rolled back, job still
 * enqueued" race.
 *
 * **Invariants (spec §7.2 line 380, §7.5 line 431):**
 *   - The row is ALWAYS persisted somewhere — `fiscal_events` (verified or
 *     in-table-quarantined) or `fiscal_event_quarantine` (sequence_conflict
 *     or malformed_envelope).
 *   - The device is NEVER blocked by a server-side anomaly: every anomaly is
 *     accepted, flagged, and routed; the ingestor's failure modes are
 *     internal-only.
 *
 * **Round-2 closures (Task 19 review):**
 *   - **T19-B1 PG transaction-abort fix.** The atomic primitive is now raw
 *     `INSERT … ON CONFLICT ON CONSTRAINT
 *     fiscal_events_tenant_terminal_sequence_unique DO NOTHING RETURNING id`
 *     (spec §7.2 line 380). A duplicate sequence slot returns an empty
 *     result set with the transaction still alive; we then issue a FRESH
 *     `SELECT` to inspect the existing row. The old
 *     `try-catch-on-QueryException` pattern aborted the surrounding PG
 *     transaction and made the follow-up SELECT unreachable.
 *   - **T19-B2 source-event-id constraint disambiguation.** The atomic
 *     `ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique`
 *     routes ONLY sequence-slot collisions through `handleConflict()`. A
 *     `(source_event_class, source_event_id)` collision propagates as a
 *     `QueryException` and is handled distinctly (log + re-throw — the row
 *     is then surfaced to the controller as a 5xx since a duplicate
 *     source-event-id with mismatched payload is a programming bug, not a
 *     chain anomaly).
 *   - **T19-B3 genesis-seed validation for first events.** The
 *     `pos_terminals.genesis_seed` column is canonical (migration
 *     `2026_01_08_190429_create_pos_terminals_table.php:41`). First events
 *     (`prior === null && sequence_number === 1`) MUST present a
 *     `previous_hash` equal to the terminal's genesis seed; mismatch quarantines
 *     as `sequence_gap`. Terminal-not-found also routes to `sequence_gap`.
 *   - **T19-B4 envelope-shape invariants.** A malformed hash / UUID /
 *     timestamp is now caught at the ingestor boundary via
 *     `FiscalEventEnvelope::assertWireShape()` BEFORE any DB write —
 *     routed to `fiscal_event_quarantine` with class
 *     `malformed_envelope` (the quarantine CHECK was widened in migration
 *     `2026_05_14_100007_widen_quarantine_class_check_for_malformed_envelope.php`).
 *   - **T19-P1 / F7 clock drift threshold.** Now read from
 *     `config('fiscal.clock_drift_limit_seconds')`; default 86400 seconds
 *     stays the conservative ceiling but operators can override per
 *     deployment.
 *   - **T19-P2 / F6 server_received_at from DB NOW().** The persisted
 *     timestamp is now driver-fetched from PostgreSQL `NOW()` (UTC) so
 *     horizontally-scaled web boxes converge on the same clock; SQLite
 *     falls back to `CURRENT_TIMESTAMP` for parity.
 *   - **F3 dead-code wrap removed.** Task 18's registry catches resolver
 *     throws internally; the OutboxIngestor's defensive wrap around
 *     `activeProjectorsFor()` was unreachable in steady state (the only
 *     remaining throw path was the registry constructor at container
 *     resolution, which surfaces at OutboxIngestor instantiation, never
 *     inside `ingest()`).
 *
 * **Carry-forward standing patterns (handoff §4.2):**
 *   - **No `(type) $array['key']` casts.** Inputs are typed
 *     `FiscalEventEnvelope` fields; reads from `stdClass` rows from the DB
 *     use explicit string casts only after `is_string`/`is_int` guards.
 *   - **Fail-closed on downstream-service exception** (Task 18 BLOCKER F1).
 *     The registry's `activeProjectorsFor()` catches resolver throws
 *     internally; this class trusts that contract.
 *   - **Regex-validate free-form fields at the boundary** (Task 15/16
 *     P1-1 pattern, T19-B4). `FiscalEventEnvelope::assertWireShape()` is
 *     re-run at the ingestor boundary so non-HTTP callers can't bypass
 *     the controller's validation step.
 *
 * **§10 clock check (Phase 1 implementation).** The spec leaves the
 * thresholds normative-text-only. Phase 1 implements two cases:
 *   1. **Rollback** — `event_time_device` strictly less than the prior
 *      event's `event_time_device` on the same terminal.
 *   2. **Excessive drift** — `|event_time_device - server_received_at| >
 *      config('fiscal.clock_drift_limit_seconds')`. A typical NTP-drifted
 *      device is well inside this window; anything outside is implausible.
 *  Both are accept-and-flag (§8 row 3); projection proceeds.
 */
final class OutboxIngestor
{
    /**
     * The one BINARY column written by this service — `bytea` on PostgreSQL,
     * `blob` on SQLite. Bound as a stream, never as a plain string. See
     * byteaStream().
     */
    private const BYTEA_COLUMN = 'canonical_bytes';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly StrictCanonicalParser $parser,
        private readonly FiscalIntegrityProvider $integrity,
        private readonly FiscalEventProjectionRegistry $projectionRegistry,
        // Task 25 — clock-anomaly detection extracted out so the same
        // admissibility surface (§10) is callable from non-ingestor
        // callers (verifier, resolver) without dragging the ingestor in.
        // Constructor-injected, no nullable default — CLAUDE.md rule 13 +
        // Task 24 round-3 lesson. The Laravel container auto-resolves it
        // (the detector has zero constructor dependencies beyond
        // `config('fiscal.clock_drift_limit_seconds')`).
        private readonly ClockAnomalyDetector $clockAnomalyDetector,
    ) {}

    /**
     * Ingest a single envelope into the server-side fiscal ledger.
     *
     * Returns an `IngestionResult` discriminating among the §7.2 outcomes:
     * stored (verified or quarantined-in-table) / idempotent
     * re-delivery / sequence_conflict / malformed_envelope (both routed to
     * `fiscal_event_quarantine`).
     */
    public function ingest(FiscalEventEnvelope $envelope): IngestionResult
    {
        // ---- Step 0: shape invariants (T19-B4) ----
        // Reject malformed hashes / UUIDs / timestamps BEFORE any DB write
        // so the row never surfaces as a PG CHECK constraint violation.
        // Routes to fiscal_event_quarantine with class malformed_envelope.
        try {
            $envelope->assertWireShape();
        } catch (InvalidArgumentException $shapeException) {
            return $this->quarantineMalformedEnvelope($envelope, $shapeException);
        }

        if ($envelope->eventType->isServerOnly()) {
            return IngestionResult::rejectedServerOnly();
        }

        // ---- Step 1: validate against the envelope, BEFORE any insert ----
        // T19-P2: server_received_at is driver-fetched (PG NOW() / SQLite
        // CURRENT_TIMESTAMP) so the persisted timestamp doesn't vary
        // across horizontally-scaled web boxes.
        $serverReceivedAt = $this->fetchServerNow();

        $hashOk = $this->verifyHash($envelope);
        $parseResult = $this->parser->parse($envelope->canonicalBytes, $envelope->eventType);
        $sealedCoordinateFailure = $this->validateSealedCoordinates($envelope, $parseResult);
        if ($sealedCoordinateFailure !== null) {
            $parseResult = ParseResult::failure($sealedCoordinateFailure);
        }

        /** @var stdClass|null $prior */
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->orderByDesc('sequence_number')
            ->first();

        $linkageVerdict = $this->verifyLinkage($envelope, $prior);
        $lifecycleVerdict = $this->verifyZSessionLifecycle($envelope, $parseResult);
        if ($lifecycleVerdict !== null) {
            $linkageVerdict = $linkageVerdict === null
                ? $lifecycleVerdict
                : $linkageVerdict.'|'.$lifecycleVerdict;
        }
        $clockVerdict = $this->verifyClock($envelope, $prior, $serverReceivedAt);

        // Priority ordering — every quarantine class is independent, but a
        // single row carries ONE integrity_exception_class. The §7.2 flow
        // checks hash, then linkage, then clock, then parse_result. The
        // first failure wins; subsequent issues are still recorded in the
        // free-form `integrity_exception_reason` for forensics.
        [
            'integrity_status' => $integrityStatus,
            'integrity_exception_class' => $exceptionClass,
            'integrity_exception_reason' => $exceptionReason,
            'payload' => $payload,
            'payload_parse_status' => $payloadParseStatus,
        ] = $this->deriveIntegrity(
            hashOk: $hashOk,
            linkageVerdict: $linkageVerdict,
            clockVerdict: $clockVerdict,
            parseResult: $parseResult,
        );

        // ---- Step 2: atomic insert (transaction T1) ----
        $row = $this->buildInsertRow($envelope, $serverReceivedAt, $integrityStatus, $exceptionClass, $exceptionReason, $payload, $payloadParseStatus);

        try {
            return $this->db->transaction(function () use ($envelope, $row, $integrityStatus, $exceptionClass, $exceptionReason, $serverReceivedAt, $payloadParseStatus, $parseResult): IngestionResult {
                // T19-B1: raw INSERT ... ON CONFLICT ON CONSTRAINT
                // fiscal_events_tenant_terminal_sequence_unique DO NOTHING
                // RETURNING id. Empty result => slot occupied; the
                // transaction is STILL alive (no abort) so the follow-up
                // SELECT in handleConflict() works. The old
                // try-catch-on-QueryException pattern aborted the PG
                // transaction.
                //
                // T19-B2: targeting the constraint NAME (not "any unique
                // violation") means a (source_event_class, source_event_id)
                // collision propagates as a QueryException — handled
                // distinctly in the outer catch.
                $inserted = $this->insertOnConflictDoNothingReturningId($row);

                if ($inserted === null) {
                    // Slot occupied — examine existing row in a FRESH
                    // statement (transaction is still alive, unlike under
                    // the old try-catch pattern).
                    return $this->handleConflict($envelope, $serverReceivedAt, $payloadParseStatus, $parseResult);
                }

                // ---- Step 3: inserted — dispatch projection rows ----
                $this->dispatchProjections($inserted, $envelope, $integrityStatus, $exceptionClass, $exceptionReason);

                if ($integrityStatus === IntegrityStatus::Quarantined && $exceptionClass !== null) {
                    return IngestionResult::quarantined($inserted, $exceptionClass);
                }

                return IngestionResult::stored($inserted);
            });
        } catch (QueryException $e) {
            // T19-B2: a source-event-id unique violation surfaces here.
            // Distinguish from other QueryExceptions so the operator can
            // diagnose. A duplicate (source_event_class, source_event_id)
            // with mismatched payload is a device authoring bug (the
            // device should never reuse the same source-event-id for a
            // different fiscal event); we log critical and re-throw so the
            // controller (Task 20) surfaces a 5xx.
            if ($this->isSourceEventIdViolation($e)) {
                Log::critical(
                    'OutboxIngestor: source_event_id uniqueness violation — same (source_event_class, source_event_id) '.
                    'tuple already exists on a different fiscal_events row. Device authoring bug.',
                    [
                        'envelope_id' => $envelope->envelopeId,
                        'fiscal_event_id' => $envelope->id,
                        'tenant_id' => $envelope->tenantId,
                        'terminal_id' => $envelope->terminalId,
                        'source_event_class' => $envelope->sourceEventClass,
                        'source_event_id' => $envelope->sourceEventId,
                        'sqlstate' => $e->getCode(),
                        'driver_message' => $e->getMessage(),
                    ],
                );
                throw $e;
            }

            // Fall through to the generic Throwable catch below.
            $this->logUnexpectedIngestException($envelope, $e);
            throw $e;
        } catch (Throwable $e) {
            // Last-resort fail-closed guard. The fiscal_events insert path is
            // atomic at the DB layer; the only path here is "ingest workflow
            // itself threw" (e.g. registry constructor failure at first
            // resolution — though as a singleton it fires at app boot, not
            // per-ingest). Per §7.2 line 380 + handoff §4.2 standing pattern
            // 4 the device must never be blocked — we surface a structured
            // error log and re-throw so the controller (Task 20) can return
            // a 5xx without leaking internals to the device.
            $this->logUnexpectedIngestException($envelope, $e);
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Step 0 — malformed envelope short-circuit (T19-B4)
    // ------------------------------------------------------------------

    private function quarantineMalformedEnvelope(FiscalEventEnvelope $envelope, InvalidArgumentException $shapeException): IngestionResult
    {
        // The envelope's hash/UUID/timestamp shape failed canonical regex
        // validation; we cannot admit the row to fiscal_events (the PG
        // CHECK constraints would reject it). We CAN preserve the verbatim
        // raw envelope for forensics in fiscal_event_quarantine — but
        // only when the quarantine table's own CHECK constraints + PG
        // column types accept every mirrored field.
        //
        // The quarantine table:
        //   - CHECK-constrains `previous_hash` / `current_hash` to 64-hex
        //   - PG-types `envelope_event_id` / `tenant_id` / `terminal_id`
        //     / `company_id` / `operator_id` / `reference_*` /
        //     `source_event_id` / `conflicting_event_id` as `uuid`
        //
        // If ANY of these is malformed at the envelope, the quarantine
        // row can't be persisted either. We fall back to a structured
        // log and re-throw the original InvalidArgumentException so the
        // controller (Task 20) renders a 422 to the device.

        $quarantineSafe = $this->envelopeQuarantineSafe($envelope);

        if (! $quarantineSafe) {
            Log::critical(
                'OutboxIngestor: malformed envelope rejected at boundary — cannot persist to fiscal_event_quarantine '.
                'either (its CHECK constraints / column types reject the malformed field). '.
                'Surfacing as ingest exception for controller 422.',
                [
                    'envelope_id' => $envelope->envelopeId,
                    'fiscal_event_id' => $envelope->id,
                    'tenant_id' => $envelope->tenantId,
                    'terminal_id' => $envelope->terminalId,
                    'shape_violation' => $shapeException->getMessage(),
                ],
            );

            throw $shapeException;
        }

        $reason = 'malformed_envelope:'.$shapeException->getMessage();

        $row = $this->buildQuarantineRow(
            envelope: $envelope,
            serverReceivedAt: $this->fetchServerNow(),
            conflictingEventId: $envelope->id,
            integrityClass: IntegrityExceptionClass::MalformedEnvelope,
            reason: $reason,
            payloadParseStatus: PayloadParseStatus::Failed,
        );

        try {
            $this->insertQuarantineRow($row);
        } catch (QueryException $insertException) {
            // Even the quarantine table refused the row — typically a PG
            // `timestamptz` or `uuid` column type mismatch beyond what
            // envelopeQuarantineSafe() pre-screens (we screen UUIDs +
            // hashes; PG's strptime accepts many timestamp forms but
            // not all). Re-throw the original shape exception so the
            // controller (Task 20) renders 422.
            Log::critical(
                'OutboxIngestor: malformed envelope rejected at boundary AND at quarantine insert — '.
                'surfacing as ingest exception for controller 422.',
                [
                    'envelope_id' => $envelope->envelopeId,
                    'fiscal_event_id' => $envelope->id,
                    'tenant_id' => $envelope->tenantId,
                    'terminal_id' => $envelope->terminalId,
                    'shape_violation' => $shapeException->getMessage(),
                    'quarantine_insert_failure' => $insertException->getMessage(),
                ],
            );

            throw $shapeException;
        }

        Log::critical('OutboxIngestor: malformed_envelope — admin alert', [
            'tenant_id' => $envelope->tenantId,
            'terminal_id' => $envelope->terminalId,
            'envelope_event_id' => $envelope->id,
            'reason' => $reason,
        ]);

        return IngestionResult::malformedEnvelope();
    }

    // ------------------------------------------------------------------
    // Step 1 — validation
    // ------------------------------------------------------------------

    private function verifyHash(FiscalEventEnvelope $envelope): bool
    {
        return $this->integrity->verify($envelope->canonicalBytes, $envelope->currentHash);
    }

    private function validateSealedCoordinates(FiscalEventEnvelope $envelope, ParseResult $parseResult): ?string
    {
        if (! $parseResult->ok || $parseResult->envelope === null) {
            return null;
        }

        $expected = [
            'tenant_id' => $envelope->tenantId,
            'company_id' => $envelope->companyId,
            'terminal_id' => $envelope->terminalId,
            'operator_id' => $envelope->operatorId,
            'event_type' => $envelope->eventType->value,
            'event_version' => $envelope->eventVersion,
            'signature_version' => $envelope->signatureVersion,
            'sequence_number' => $envelope->sequenceNumber,
            'event_time_device' => $envelope->eventTimeDevice,
            'business_date' => $envelope->businessDate,
            'chain_context' => $envelope->chainContext,
            'reference_event_id' => $envelope->referenceEventId,
            'reference_document_id' => $envelope->referenceDocumentId,
            'previous_hash' => $envelope->previousHash,
        ];

        foreach ($expected as $field => $outerValue) {
            $sealedValue = $parseResult->envelope[$field] ?? null;
            if ($sealedValue !== $outerValue) {
                return sprintf(
                    'sealed_coordinate_mismatch:field=%s,outer=%s,canonical=%s',
                    $field,
                    $this->forensicScalar($outerValue),
                    $this->forensicScalar($sealedValue),
                );
            }
        }

        return null;
    }

    private function forensicScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : get_debug_type($value);
        }

        return get_debug_type($value);
    }

    /**
     * Verify chain linkage (§7.2 line 337).
     *
     * Returns either `null` (linkage OK) or a structured reason for why
     * linkage failed. Phase 1 contract:
     *   - For a non-first event: sequence_number == prior+1 AND
     *     previous_hash == prior.current_hash. A numeric gap with valid
     *     hash linkage is STILL sequence_gap (plan §1444 invariant).
     *   - For a first event (no prior row): sequence_number == 1 AND
     *     previous_hash == terminal's genesis_seed (T19-B3 — the
     *     `pos_terminals.genesis_seed` column lives on the server, so the
     *     check is enforced here and not deferred to the verifier).
     */
    private function verifyLinkage(FiscalEventEnvelope $envelope, ?stdClass $prior): ?string
    {
        if ($prior === null) {
            if ($envelope->sequenceNumber !== 1) {
                return sprintf(
                    'sequence_gap:no_prior_row_but_sequence=%d_must_be_1',
                    $envelope->sequenceNumber,
                );
            }

            // T19-B3: first-event genesis-seed check. previous_hash must
            // equal the terminal's pos_terminals.genesis_seed.
            $genesisSeed = $this->fetchGenesisSeed($envelope->terminalId);

            if ($genesisSeed === null) {
                return sprintf(
                    'sequence_gap:terminal_not_found_for_genesis_seed_lookup,terminal_id=%s',
                    $envelope->terminalId,
                );
            }

            // Sentinel: terminal-lookup-failed (DB error). We fail closed
            // so a transient DB hiccup quarantines the row rather than
            // silently admitting it.
            if ($genesisSeed === self::LOOKUP_FAILED) {
                return 'sequence_gap:terminal_lookup_failed_db_error';
            }

            if (! hash_equals(strtolower($genesisSeed), strtolower($envelope->previousHash))) {
                // Do not leak the full seed in cleartext; first 8 chars
                // are enough to disambiguate during forensics without
                // weakening operational secrecy.
                return sprintf(
                    'sequence_gap:genesis_seed_mismatch,expected_prefix=%s...,got_prefix=%s...',
                    substr($genesisSeed, 0, 8),
                    substr($envelope->previousHash, 0, 8),
                );
            }

            return null;
        }

        $priorSeq = is_int($prior->sequence_number)
            ? $prior->sequence_number
            : (int) $prior->sequence_number;
        $priorHash = is_string($prior->current_hash) ? $prior->current_hash : '';

        $errors = [];
        if ($envelope->sequenceNumber !== $priorSeq + 1) {
            $errors[] = sprintf('numeric_gap:expected=%d,got=%d', $priorSeq + 1, $envelope->sequenceNumber);
        }
        if ($envelope->previousHash !== $priorHash) {
            $errors[] = 'hash_linkage_broken:previous_hash_does_not_match_prior_current_hash';
        }

        return $errors === [] ? null : 'sequence_gap:'.implode('|', $errors);
    }

    private function verifyZSessionLifecycle(FiscalEventEnvelope $envelope, ParseResult $parseResult): ?string
    {
        if (! in_array($envelope->chainContext, ['z_session', 'training_z_session'], true)) {
            return null;
        }
        if (! $parseResult->ok || $parseResult->payload === null) {
            return null;
        }

        $sessionId = $parseResult->payload['session_id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            return 'z_session_lifecycle:missing_session_id';
        }

        if ($envelope->eventType === FiscalEventType::SESSION_OPEN) {
            if ($envelope->sourceEventClass !== 'pos_session' || $envelope->sourceEventId !== $sessionId) {
                return 'z_session_lifecycle:session_open_source_mismatch';
            }

            $existingOpen = $this->findZSessionOpen($envelope, $sessionId);

            return $existingOpen === null
                ? null
                : 'z_session_lifecycle:duplicate_session_open';
        }

        $sessionOpen = $this->findZSessionOpen($envelope, $sessionId);
        if ($sessionOpen === null) {
            return 'z_session_lifecycle:missing_session_open';
        }

        if ($this->hasZReportForSession($envelope, $sessionId)) {
            return 'z_session_lifecycle:session_already_z_reported';
        }

        if ($envelope->eventType === FiscalEventType::Z_REPORT) {
            $sessionCloseEventId = $this->sessionCloseEventIdFromPayload($parseResult->payload);
            if ($sessionCloseEventId === null) {
                return 'z_session_lifecycle:missing_session_close_reference';
            }
            if (! $this->sessionCloseExists($envelope, $sessionId, $sessionCloseEventId)) {
                return 'z_session_lifecycle:missing_session_close';
            }
        }

        return null;
    }

    private function findZSessionOpen(FiscalEventEnvelope $envelope, string $sessionId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->where('event_type', FiscalEventType::SESSION_OPEN->value)
            ->where('payload->session_id', $sessionId)
            ->first(['id']);

        return $row;
    }

    private function hasZReportForSession(FiscalEventEnvelope $envelope, string $sessionId): bool
    {
        $rows = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->where('event_type', FiscalEventType::Z_REPORT->value)
            ->get(['payload']);

        foreach ($rows as $row) {
            $payload = $row->payload ?? null;
            if (is_string($payload)) {
                /** @var mixed $decoded */
                $decoded = json_decode($payload, true);
                $payload = $decoded;
            }
            if (is_array($payload) && ($payload['session_id'] ?? null) === $sessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sessionCloseEventIdFromPayload(array $payload): ?string
    {
        $range = $payload['session_event_range'] ?? null;
        if (! is_array($range)) {
            return null;
        }
        $eventId = $range['session_close_event_id'] ?? null;

        return is_string($eventId) && $eventId !== '' ? $eventId : null;
    }

    private function sessionCloseExists(FiscalEventEnvelope $envelope, string $sessionId, string $sessionCloseEventId): bool
    {
        return $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->where('event_type', FiscalEventType::SESSION_CLOSE->value)
            ->where('id', $sessionCloseEventId)
            ->where('payload->session_id', $sessionId)
            ->exists();
    }

    /** Sentinel returned by `fetchGenesisSeed()` when the DB lookup throws. */
    private const LOOKUP_FAILED = '__terminal_lookup_failed_db_error__';

    /**
     * Look up `pos_terminals.genesis_seed` for the given terminal id.
     *
     * Returns:
     *   - the 64-char hex genesis_seed on success
     *   - `null` when the terminal does not exist
     *   - `self::LOOKUP_FAILED` when the DB lookup itself raised (e.g. a
     *     malformed UUID would raise on PG's `uuid`-typed column). Per
     *     the Task 17 standing pattern (fail-closed on downstream
     *     exception) we surface this as a `sequence_gap` quarantine
     *     reason rather than crashing the ingest path.
     */
    private function fetchGenesisSeed(string $terminalId): ?string
    {
        try {
            /** @var stdClass|null $terminal */
            $terminal = $this->db->table('pos_terminals')
                ->where('id', $terminalId)
                ->first(['genesis_seed']);
        } catch (QueryException $e) {
            Log::warning(
                'OutboxIngestor: pos_terminals.genesis_seed lookup raised; failing closed.',
                [
                    'terminal_id' => $terminalId,
                    'sqlstate' => $e->getCode(),
                    'message' => $e->getMessage(),
                ],
            );

            return self::LOOKUP_FAILED;
        }

        if ($terminal === null) {
            return null;
        }

        return is_string($terminal->genesis_seed)
            ? $terminal->genesis_seed
            : (string) $terminal->genesis_seed;
    }

    /**
     * §10 clock check — delegates to `ClockAnomalyDetector::isWithinTolerance()`
     * (Task 25) and reconstructs the structured forensic reason on a
     * fail. The detector is the single authority for §10 admissibility;
     * the ingestor still owns the *reason-string format* because it's
     * the field that ends up in `integrity_exception_reason` and the
     * verifier reads it back.
     *
     * Returns `null` when clock is admissible, otherwise a structured
     * reason. Two cases — rollback vs prior, excessive drift vs server
     * time. A malformed `event_time_device` (Carbon parse failure) is
     * defensively treated as admissible by the detector: the
     * StrictCanonicalParser also enforces the ISO-8601 format on the
     * canonical bytes, so a malformed timestamp here would already have
     * been caught at the parse layer. Belt-and-braces — never throw out
     * of the clock check.
     */
    private function verifyClock(FiscalEventEnvelope $envelope, ?stdClass $prior, CarbonImmutable $serverReceivedAt): ?string
    {
        $priorTimeRaw = $prior === null
            ? null
            : (is_string($prior->event_time_device)
                ? $prior->event_time_device
                : (string) $prior->event_time_device);

        if ($this->clockAnomalyDetector->isWithinTolerance(
            deviceTime: $envelope->eventTimeDevice,
            lastServerTimeSeen: $priorTimeRaw,
            serverReceivedAt: $serverReceivedAt,
        )) {
            return null;
        }

        // Detector flagged. Format the structured reason via the detector
        // — round-2 Opus F2/F5 closure. Single source of truth: the
        // detector owns BOTH the admissibility decision AND the threshold
        // it uses, so the reason string the verifier reads cannot
        // misreport the limit the detector enforced.
        return $this->clockAnomalyDetector->formatTimeAnomalyReason(
            deviceTime: $envelope->eventTimeDevice,
            lastServerTimeSeen: $priorTimeRaw,
            serverReceivedAt: $serverReceivedAt,
        );
    }

    /**
     * Derive integrity columns + payload from the four §7.2 Step 1 checks.
     *
     * Priority order (§7.2 line 336+): hash → linkage → clock → parse.
     * The first failing class becomes the row's `integrity_exception_class`;
     * subsequent issues append to `integrity_exception_reason` so
     * forensics see the complete picture.
     *
     * @return array{
     *   integrity_status: IntegrityStatus,
     *   integrity_exception_class: ?IntegrityExceptionClass,
     *   integrity_exception_reason: ?string,
     *   payload: ?array<string, mixed>,
     *   payload_parse_status: PayloadParseStatus
     * }
     */
    private function deriveIntegrity(bool $hashOk, ?string $linkageVerdict, ?string $clockVerdict, ParseResult $parseResult): array
    {
        $reasons = [];
        if (! $hashOk) {
            $reasons[] = 'canonical_hash_mismatch:sha256(canonical_bytes)!=current_hash';
        }
        if ($linkageVerdict !== null) {
            $reasons[] = $linkageVerdict;
        }
        if ($clockVerdict !== null) {
            $reasons[] = $clockVerdict;
        }
        if (! $parseResult->ok) {
            $reasons[] = 'canonical_parse_failure:'.($parseResult->failureReason ?? 'unknown');
        }

        // Determine winning class (first one to fire by spec priority).
        $exceptionClass = match (true) {
            ! $hashOk => IntegrityExceptionClass::CanonicalHashMismatch,
            $linkageVerdict !== null => IntegrityExceptionClass::SequenceGap,
            $clockVerdict !== null => IntegrityExceptionClass::TimeAnomaly,
            ! $parseResult->ok => IntegrityExceptionClass::CanonicalParseFailure,
            default => null,
        };

        // canonical_parse_failure → payload NULL + payload_parse_status='failed'.
        // Everything else (verified OR quarantined-but-parseable) stores
        // the parsed payload and marks payload_parse_status='parsed'.
        if ($exceptionClass === IntegrityExceptionClass::CanonicalParseFailure || ! $parseResult->ok) {
            $payload = null;
            $payloadParseStatus = PayloadParseStatus::Failed;
        } else {
            $payload = $parseResult->payload;
            $payloadParseStatus = PayloadParseStatus::Parsed;
        }

        $integrityStatus = $exceptionClass === null
            ? IntegrityStatus::Verified
            : IntegrityStatus::Quarantined;

        return [
            'integrity_status' => $integrityStatus,
            'integrity_exception_class' => $exceptionClass,
            'integrity_exception_reason' => $reasons === [] ? null : implode(';', $reasons),
            'payload' => $payload,
            'payload_parse_status' => $payloadParseStatus,
        ];
    }

    // ------------------------------------------------------------------
    // Step 2 — INSERT row build + atomic insert (T19-B1)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function buildInsertRow(
        FiscalEventEnvelope $envelope,
        CarbonImmutable $serverReceivedAt,
        IntegrityStatus $integrityStatus,
        ?IntegrityExceptionClass $exceptionClass,
        ?string $exceptionReason,
        ?array $payload,
        PayloadParseStatus $payloadParseStatus,
    ): array {
        return [
            'id' => $envelope->id,
            'tenant_id' => $envelope->tenantId,
            'company_id' => $envelope->companyId,
            'terminal_id' => $envelope->terminalId,
            'operator_id' => $envelope->operatorId,
            'event_type' => $envelope->eventType->value,
            'event_version' => $envelope->eventVersion,
            'signature_version' => $envelope->signatureVersion,
            'sequence_number' => $envelope->sequenceNumber,
            'event_time_device' => $envelope->eventTimeDevice,
            'business_date' => $envelope->businessDate,
            'chain_context' => $envelope->chainContext,
            'last_server_time_seen' => $envelope->lastServerTimeSeen,
            'server_received_at' => $serverReceivedAt->toDateTimeString(),
            'reference_event_id' => $envelope->referenceEventId,
            'reference_document_id' => $envelope->referenceDocumentId,
            'source_event_class' => $envelope->sourceEventClass,
            'source_event_id' => $envelope->sourceEventId,
            'canonical_bytes' => $envelope->canonicalBytes,
            'previous_hash' => $envelope->previousHash,
            'current_hash' => $envelope->currentHash,
            'signature_status' => SignatureStatus::NotRequired->value,
            'integrity_status' => $integrityStatus->value,
            'integrity_exception_class' => $exceptionClass?->value,
            'integrity_exception_reason' => $exceptionReason,
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'payload_parse_status' => $payloadParseStatus->value,
        ];
    }

    /**
     * T19-B1: raw `INSERT … ON CONFLICT ON CONSTRAINT
     * fiscal_events_tenant_terminal_sequence_unique DO NOTHING RETURNING id`.
     *
     * Returns the inserted id on success, `null` when the row was
     * suppressed by a sequence-slot conflict (the transaction is STILL
     * alive — unlike the old try-catch-on-QueryException pattern, which
     * aborted the surrounding PG transaction).
     *
     * Targeting the constraint NAME (not "any unique violation") ensures
     * a `(source_event_class, source_event_id)` collision propagates as
     * a QueryException — T19-B2 — so the outer catch can disambiguate
     * the two paths.
     *
     * On SQLite the constraint-name targeting falls back to the unnamed
     * `ON CONFLICT DO NOTHING` form (SQLite supports `INSERT ... ON
     * CONFLICT DO NOTHING RETURNING ...` since 3.35; PHP 8.4's bundled
     * SQLite is well past that). The portable form still routes a
     * source-event-id violation through QueryException.
     *
     * @param  array<string, mixed>  $row
     */
    private function insertOnConflictDoNothingReturningId(array $row): ?string
    {
        $driver = $this->driverName();
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns));

        // `canonical_bytes` is a BINARY column — it must be bound as a stream
        // (PDO::PARAM_LOB), never as a plain string. See byteaStream().
        $streams = [];
        $bindings = [];

        foreach ($row as $column => $value) {
            if ($column === self::BYTEA_COLUMN && is_string($value)) {
                $stream = $this->byteaStream($value);
                $streams[] = $stream;
                $bindings[] = $stream;

                continue;
            }

            $bindings[] = $value;
        }

        if ($driver === 'pgsql') {
            $sql = sprintf(
                'INSERT INTO "fiscal_events" (%s) VALUES (%s) '.
                'ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique '.
                'DO NOTHING RETURNING id',
                $columnList,
                $placeholders,
            );
        } else {
            // SQLite + others — explicit conflict-target on the chain slot
            // so a source-event-id collision still propagates as a
            // QueryException.
            $sql = sprintf(
                'INSERT INTO "fiscal_events" (%s) VALUES (%s) '.
                'ON CONFLICT (tenant_id, company_id, terminal_id, chain_context, sequence_number) '.
                'DO NOTHING RETURNING id',
                $columnList,
                $placeholders,
            );
        }

        try {
            $rows = $this->db->select($sql, $bindings);
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $id = is_object($first) && property_exists($first, 'id') ? $first->id : null;

        return is_string($id) ? $id : (is_scalar($id) ? (string) $id : null);
    }

    /**
     * Insert one `fiscal_event_quarantine` row, binding the binary
     * `canonical_bytes` column as a stream. See byteaStream().
     *
     * @param  array<string, mixed>  $row
     */
    private function insertQuarantineRow(array $row): void
    {
        $bytes = $row[self::BYTEA_COLUMN] ?? null;

        if (! is_string($bytes)) {
            $this->db->table('fiscal_event_quarantine')->insert($row);

            return;
        }

        $stream = $this->byteaStream($bytes);
        $row[self::BYTEA_COLUMN] = $stream;

        try {
            $this->db->table('fiscal_event_quarantine')->insert($row);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Wrap raw bytes in an in-memory stream so PDO binds them as
     * `PDO::PARAM_LOB` instead of `PDO::PARAM_STR`.
     *
     * `canonical_bytes` is `bytea` on PostgreSQL and `blob` on SQLite.
     * `Illuminate\Database\Connection::bindValues()` binds a plain PHP string
     * as `PDO::PARAM_STR`, which is transmitted as a text literal — so
     * PostgreSQL parses it with the bytea *escape* input rules and every
     * backslash that is not `\\` or `\NNN` fails with
     * `SQLSTATE[22P02] invalid input syntax for type bytea`. RFC 8785
     * canonical JSON emits `\"` for every quote in free text, so any receipt
     * whose product name or note contains a quote or a backslash would be
     * un-ingestable on PostgreSQL.
     *
     * A resource binds as `PDO::PARAM_LOB`, which is sent as binary and
     * round-trips byte-identically on BOTH drivers.
     *
     * The caller MUST `fclose()` the stream once the statement has executed.
     *
     * @return resource
     */
    private function byteaStream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new RuntimeException(
                'OutboxIngestor: unable to open an in-memory stream for the canonical_bytes binding.',
            );
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    // ------------------------------------------------------------------
    // Step 3 — projection dispatch
    // ------------------------------------------------------------------

    /**
     * Insert one `fiscal_event_projections` row per active projector,
     * inside the same transaction T1 as the fiscal_events insert.
     * Suppressed for `canonical_parse_failure` (§7.5 — no trusted payload
     * to project; resume via `fiscal:enqueue-resolved-event-projections`).
     *
     * After T1 commits, dispatch one `ApplyFiscalEventProjectionJob`
     * per pending row via `DB::afterCommit()` so an aborted T1 produces
     * no spurious jobs. The closure is `static` (no `$this` capture) so
     * the queued payload doesn't drag the ingestor instance in.
     *
     * **F3 round-2.** The defensive try/catch around `activeProjectorsFor()`
     * is gone — Task 18's registry catches resolver throws internally
     * (F1), and the registry's constructor-time invariants (F2/F3) fire at
     * container resolution time, never inside this method. The path was
     * unreachable in steady state.
     */
    private function dispatchProjections(
        string $fiscalEventId,
        FiscalEventEnvelope $envelope,
        IntegrityStatus $integrityStatus,
        ?IntegrityExceptionClass $exceptionClass,
        ?string $exceptionReason,
    ): void {
        if ($exceptionClass === IntegrityExceptionClass::CanonicalParseFailure) {
            // §7.5 suppression — no trusted payload to project.
            return;
        }
        if ($exceptionReason !== null && str_contains($exceptionReason, 'z_session_lifecycle:')) {
            return;
        }
        unset($integrityStatus); // marker for future spec change — every other quarantine class still projects

        // Hydrate a FiscalEvent model so the registry can call
        // `handlesEventType()` + the resolver. This is a transient model —
        // we have not yet saved it through Eloquent (the raw DB::insert
        // above wrote the row directly), so we forceFill the typed values
        // the registry inspects.
        $eventModel = new FiscalEvent;
        $eventModel->forceFill([
            'id' => $fiscalEventId,
            'tenant_id' => $envelope->tenantId,
            'company_id' => $envelope->companyId,
            'event_type' => $envelope->eventType,
        ]);

        $activeProjectors = $this->projectionRegistry->activeProjectorsFor($eventModel);

        $now = Carbon::now('UTC')->toDateTimeString();
        $pendingRows = [];
        foreach ($activeProjectors as $projector) {
            $pendingRows[] = [
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $fiscalEventId,
                'projector_name' => $projector->name(),
                'projection_status' => 'pending',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($pendingRows !== []) {
            $this->db->table('fiscal_event_projections')->insert($pendingRows);
        }

        // After-commit hook: enqueue one ApplyFiscalEventProjectionJob per
        // pending row. The closure is `static` (no `$this` capture) so a
        // serialized queue payload doesn't drag the ingestor instance in.
        // `DB::afterCommit()` honors the OUTER transaction's rollback —
        // jobs only fire if the surrounding commit succeeds (F4 round-2
        // pattern).
        //
        // Task 23 lands here: the previous TODO no-op is replaced with the
        // real dispatch. Rows arrive priority-sorted (Task 22 round-2 —
        // the registry's `(priority ASC, name ASC)` sort), so dispatch
        // order mirrors lifecycle dependency (POS-core first @ 50, Treasury
        // bridge after @ 150).
        $rowsForDispatch = $pendingRows;
        DB::afterCommit(static function () use ($rowsForDispatch): void {
            foreach ($rowsForDispatch as $row) {
                ApplyFiscalEventProjectionJob::dispatch($row['id']);
            }
        });
    }

    // ------------------------------------------------------------------
    // Step 4 — conflict handling
    // ------------------------------------------------------------------

    /**
     * Normalize a raw `canonical_bytes` value read via the query builder.
     *
     * On PostgreSQL a `bytea` column selected through `DB::table()` (not Eloquent)
     * comes back as a stream resource, so `(string) $resource` yields
     * "Resource id #N" — which makes the hash_equals() comparison below never match
     * and misroutes a legitimate idempotent re-delivery into quarantine. Eloquent
     * reads apply the FiscalEvent stream→string accessor; mirror it for the raw row.
     */
    private function normalizeCanonicalBytes(mixed $value): string
    {
        if (is_resource($value)) {
            $meta = stream_get_meta_data($value);
            if ($meta['seekable'] === true) {
                rewind($value);
            }

            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function handleConflict(
        FiscalEventEnvelope $envelope,
        CarbonImmutable $serverReceivedAt,
        PayloadParseStatus $payloadParseStatus,
        ParseResult $parseResult,
    ): IngestionResult {
        /** @var stdClass|null $existing */
        $existing = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->where('sequence_number', $envelope->sequenceNumber)
            ->first();

        if ($existing === null) {
            // Defense — UNIQUE fired but the row vanished (impossible under
            // the BEFORE DELETE trigger from Task 8, but defensible against
            // a future schema drift). Treat as a sequence_conflict — better
            // a spurious quarantine row than a silent acceptance.
            return $this->quarantineSequenceConflict(
                envelope: $envelope,
                serverReceivedAt: $serverReceivedAt,
                conflictingEventId: $envelope->id, // can't reference vanished prior — point at the envelope's own id
                reason: 'sequence_conflict:slot_unique_violation_but_existing_row_not_found',
                payloadParseStatus: $payloadParseStatus,
                parseResult: $parseResult,
            );
        }

        // Genuine idempotent re-delivery check (§7.2 Step 4):
        //   id + current_hash + canonical_bytes + source_event_(class,id) all match.
        $existingId = is_string($existing->id) ? $existing->id : (string) $existing->id;
        $existingHash = is_string($existing->current_hash) ? $existing->current_hash : (string) $existing->current_hash;
        $existingBytes = $this->normalizeCanonicalBytes($existing->canonical_bytes);
        $existingClass = $existing->source_event_class === null ? null : (is_string($existing->source_event_class) ? $existing->source_event_class : (string) $existing->source_event_class);
        $existingSrcId = $existing->source_event_id === null ? null : (is_string($existing->source_event_id) ? $existing->source_event_id : (string) $existing->source_event_id);

        $matches = $existingId === $envelope->id
            && hash_equals($existingHash, $envelope->currentHash)
            && hash_equals($existingBytes, $envelope->canonicalBytes)
            && $existingClass === $envelope->sourceEventClass
            && $existingSrcId === $envelope->sourceEventId;

        if ($matches) {
            return IngestionResult::idempotent($existingId);
        }

        // Non-identical conflict — a DIFFERENT envelope claimed an occupied
        // slot. Route to fiscal_event_quarantine (§8).
        return $this->quarantineSequenceConflict(
            envelope: $envelope,
            serverReceivedAt: $serverReceivedAt,
            conflictingEventId: $existingId,
            reason: $this->buildConflictReason($envelope, $existingId, $existingHash, $existingBytes, $existingClass, $existingSrcId),
            payloadParseStatus: $payloadParseStatus,
            parseResult: $parseResult,
        );
    }

    private function buildConflictReason(
        FiscalEventEnvelope $envelope,
        string $existingId,
        string $existingHash,
        string $existingBytes,
        ?string $existingClass,
        ?string $existingSrcId,
    ): string {
        $diff = [];
        if ($existingId !== $envelope->id) {
            $diff[] = 'id:existing!=envelope';
        }
        if (! hash_equals($existingHash, $envelope->currentHash)) {
            $diff[] = 'current_hash:existing!=envelope';
        }
        if (! hash_equals($existingBytes, $envelope->canonicalBytes)) {
            $diff[] = 'canonical_bytes:existing!=envelope';
        }
        // Opus F2: also enumerate source_event_class / source_event_id
        // diffs so the reason string is meaningful when the only
        // difference is on the source-event pointer.
        if ($existingClass !== $envelope->sourceEventClass) {
            $diff[] = 'source_event_class:existing!=envelope';
        }
        if ($existingSrcId !== $envelope->sourceEventId) {
            $diff[] = 'source_event_id:existing!=envelope';
        }

        return 'sequence_conflict:different_event_at_occupied_slot;'.implode(',', $diff !== [] ? $diff : ['unspecified_difference']);
    }

    private function quarantineSequenceConflict(
        FiscalEventEnvelope $envelope,
        CarbonImmutable $serverReceivedAt,
        string $conflictingEventId,
        string $reason,
        PayloadParseStatus $payloadParseStatus,
        ParseResult $parseResult,
    ): IngestionResult {
        unset($parseResult);

        $row = $this->buildQuarantineRow(
            envelope: $envelope,
            serverReceivedAt: $serverReceivedAt,
            conflictingEventId: $conflictingEventId,
            integrityClass: IntegrityExceptionClass::SequenceConflict,
            reason: $reason,
            payloadParseStatus: $payloadParseStatus,
        );

        $this->insertQuarantineRow($row);

        // §7.2 Step 4 line 374 — "raise an admin alert". Phase 1: emit a
        // structured Log::critical so monitoring picks it up. Task 23+
        // can wire `NotificationDispatcherInterface` (Compliance module) if
        // owner direction is to dispatch a FraudAlert — that model
        // requires a `user_id` foreign key tied to a real User row, which
        // server-side sequence_conflict envelopes don't inherently have.
        // Deferring the FraudAlert dispatch to a follow-up keeps this
        // task's contract focused on the §7.2/§8 invariants.
        Log::critical('OutboxIngestor: sequence_conflict — admin alert', [
            'tenant_id' => $envelope->tenantId,
            'terminal_id' => $envelope->terminalId,
            'claimed_sequence_number' => $envelope->sequenceNumber,
            'envelope_event_id' => $envelope->id,
            'conflicting_event_id' => $conflictingEventId,
            'reason' => $reason,
        ]);

        return IngestionResult::sequenceConflict();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuarantineRow(
        FiscalEventEnvelope $envelope,
        CarbonImmutable $serverReceivedAt,
        string $conflictingEventId,
        IntegrityExceptionClass $integrityClass,
        string $reason,
        PayloadParseStatus $payloadParseStatus,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $envelope->tenantId,
            'company_id' => $envelope->companyId,
            'terminal_id' => $envelope->terminalId,
            'operator_id' => $envelope->operatorId,
            'envelope_event_id' => $envelope->id,
            'event_type' => $envelope->eventType->value,
            'event_version' => $envelope->eventVersion,
            'signature_version' => $envelope->signatureVersion,
            'claimed_sequence_number' => $envelope->sequenceNumber,
            'event_time_device' => $envelope->eventTimeDevice,
            'business_date' => $envelope->businessDate,
            'chain_context' => $envelope->chainContext,
            'last_server_time_seen' => $envelope->lastServerTimeSeen,
            'reference_event_id' => $envelope->referenceEventId,
            'reference_document_id' => $envelope->referenceDocumentId,
            'source_event_class' => $envelope->sourceEventClass,
            'source_event_id' => $envelope->sourceEventId,
            'previous_hash' => $envelope->previousHash,
            'current_hash' => $envelope->currentHash,
            'canonical_bytes' => $envelope->canonicalBytes,
            'raw_envelope' => json_encode($this->envelopeToArray($envelope), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'payload_parse_status' => $payloadParseStatus->value,
            'integrity_exception_class' => $integrityClass->value,
            'integrity_exception_reason' => $reason,
            'conflicting_event_id' => $conflictingEventId,
            'server_received_at' => $serverReceivedAt->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeToArray(FiscalEventEnvelope $envelope): array
    {
        return [
            'envelope_id' => $envelope->envelopeId,
            'idempotency_key' => $envelope->idempotencyKey,
            'payload_version' => $envelope->payloadVersion,
            'id' => $envelope->id,
            'tenant_id' => $envelope->tenantId,
            'company_id' => $envelope->companyId,
            'terminal_id' => $envelope->terminalId,
            'operator_id' => $envelope->operatorId,
            'event_type' => $envelope->eventType->value,
            'event_version' => $envelope->eventVersion,
            'signature_version' => $envelope->signatureVersion,
            'sequence_number' => $envelope->sequenceNumber,
            'event_time_device' => $envelope->eventTimeDevice,
            'business_date' => $envelope->businessDate,
            'chain_context' => $envelope->chainContext,
            'last_server_time_seen' => $envelope->lastServerTimeSeen,
            'reference_event_id' => $envelope->referenceEventId,
            'reference_document_id' => $envelope->referenceDocumentId,
            'source_event_class' => $envelope->sourceEventClass,
            'source_event_id' => $envelope->sourceEventId,
            'previous_hash' => $envelope->previousHash,
            'current_hash' => $envelope->currentHash,
            'canonical_bytes' => $envelope->canonicalBytes,
        ];
    }

    // ------------------------------------------------------------------
    // Driver utilities
    // ------------------------------------------------------------------

    /**
     * T19-P2: fetch the server's "now" from the DRIVER (PG NOW() / SQLite
     * CURRENT_TIMESTAMP) so the persisted `server_received_at` doesn't
     * vary across horizontally-scaled web boxes (NTP drift between
     * machines).
     */
    private function fetchServerNow(): CarbonImmutable
    {
        $driver = $this->driverName();

        $sql = $driver === 'pgsql'
            ? "SELECT (NOW() AT TIME ZONE 'UTC')::text AS now"
            : 'SELECT CURRENT_TIMESTAMP AS now';

        /** @var stdClass|null $row */
        $row = $this->db->selectOne($sql);
        if ($row === null || ! is_string($row->now ?? null)) {
            // The driver returned an unexpected shape — fall back to PHP
            // wall-clock so we never throw out of the ingest path. The
            // P1 fix is best-effort: if the DB clock is unreachable,
            // PHP's clock is still the same NTP source most of the time.
            return CarbonImmutable::now('UTC');
        }

        try {
            return CarbonImmutable::parse($row->now, 'UTC');
        } catch (Throwable) {
            return CarbonImmutable::now('UTC');
        }
    }

    /**
     * Detect a (source_event_class, source_event_id) uniqueness violation
     * — T19-B2. The PG partial unique index name is
     * `fiscal_events_source_event_unique`. SQLite has no equivalent
     * (Phase 1 doesn't add it on the sqlite test driver), so the check
     * is PG-shaped; on SQLite this returns `false` (and we never reach
     * here because the sequence-slot ON CONFLICT swallowed the only
     * SQLite unique conflict).
     */
    private function isSourceEventIdViolation(QueryException $e): bool
    {
        if ($e->getCode() !== '23505') {
            return false;
        }

        return str_contains($e->getMessage(), 'fiscal_events_source_event_unique');
    }

    /**
     * Return `true` when every field mirrored into a UUID-typed or
     * CHECK-constrained column on `fiscal_event_quarantine` would be
     * accepted by PG. A malformed UUID at the envelope's `id`
     * (envelope_event_id), tenant/company/terminal/operator, or any
     * reference field would otherwise fail at the quarantine insert too.
     *
     * Hashes are 64-hex CHECK-constrained (`previous_hash`,
     * `current_hash`); everything else under `uuid` type checks on PG.
     */
    private function envelopeQuarantineSafe(FiscalEventEnvelope $envelope): bool
    {
        if (preg_match(FiscalEventEnvelope::HEX_64_REGEX, $envelope->previousHash) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::HEX_64_REGEX, $envelope->currentHash) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->id) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->tenantId) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->companyId) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->terminalId) !== 1) {
            return false;
        }
        if (preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->operatorId) !== 1) {
            return false;
        }
        if ($envelope->referenceEventId !== null && preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->referenceEventId) !== 1) {
            return false;
        }
        if ($envelope->referenceDocumentId !== null && preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->referenceDocumentId) !== 1) {
            return false;
        }
        if ($envelope->sourceEventId !== null && preg_match(FiscalEventEnvelope::UUID_REGEX, $envelope->sourceEventId) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the underlying PDO driver name (`'pgsql'` / `'sqlite'` / ...).
     *
     * `ConnectionInterface` does not expose `getDriverName()`; it lives on
     * the concrete `\Illuminate\Database\Connection`. The container binds
     * the latter to the former in practice (the default container binding
     * resolves `ConnectionInterface` via `DB::connection()`). We narrow
     * with `instanceof` so PHPStan level 8 stays clean; tests that swap
     * in a fake `ConnectionInterface` fall back to `'sqlite'` (the test
     * runtime), preserving the safe portable branch.
     */
    private function driverName(): string
    {
        if ($this->db instanceof Connection) {
            return $this->db->getDriverName();
        }

        return DB::connection()->getDriverName();
    }

    private function logUnexpectedIngestException(FiscalEventEnvelope $envelope, Throwable $e): void
    {
        Log::critical('OutboxIngestor: unexpected exception in ingest path — fiscal row may not be persisted.', [
            'envelope_id' => $envelope->envelopeId,
            'fiscal_event_id' => $envelope->id,
            'tenant_id' => $envelope->tenantId,
            'terminal_id' => $envelope->terminalId,
            'sequence_number' => $envelope->sequenceNumber,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
