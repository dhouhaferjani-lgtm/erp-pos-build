<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 *     in-table-quarantined) or `fiscal_event_quarantine` (sequence_conflict).
 *   - The device is NEVER blocked by a server-side anomaly: every anomaly is
 *     accepted, flagged, and routed; the ingestor's failure modes are
 *     internal-only.
 *
 * **Carry-forward standing patterns (handoff §4.2):**
 *   - **No `(type) $array['key']` casts.** Inputs are typed
 *     `FiscalEventEnvelope` fields; reads from `stdClass` rows from the DB
 *     use explicit string casts only after `is_string`/`is_int` guards.
 *   - **Fail-closed on downstream-service exception** (Task 18 BLOCKER F1).
 *     The fiscal_events row is committed even if the projection dispatch
 *     loop throws — the registry already catches resolver exceptions; this
 *     class additionally catches Throwable around the entire projection
 *     planning block so a registry boot-fail or other unexpected exception
 *     never crashes the ingest path.
 *   - **Regex-validate free-form fields at the boundary** (Task 15/16
 *     P1-1 pattern). The DTO carries `previous_hash` / `current_hash` /
 *     `event_time_device` / `business_date` as plain strings; the
 *     controller (Task 20) regex-validates them upstream. The ingestor
 *     trusts the DTO but defends against a malformed `event_time_device`
 *     inside the §10 clock check (CarbonImmutable parse failure → fall
 *     back to non-anomalous), so the §10 path can never bubble out.
 *
 * **§10 clock check (Phase 1 implementation).** The spec leaves the
 * thresholds normative-text-only. Phase 1 implements two cases:
 *   1. **Rollback** — `event_time_device` strictly less than the prior
 *      event's `event_time_device` on the same terminal.
 *   2. **Excessive drift** — `|event_time_device - server_received_at| >
 *      24 hours`. A typical NTP-drifted device is well inside this window;
 *      anything outside is implausible.
 *  Both are accept-and-flag (§8 row 3); projection proceeds.
 */
final class OutboxIngestor
{
    /**
     * Phase 1 acceptance window for `event_time_device` vs `server_received_at`
     * (the §10 "excessive drift" threshold). 24 hours covers worst-case
     * NTP-drifted devices + timezone surprises; anything beyond is
     * implausible enough to flag.
     */
    private const CLOCK_DRIFT_LIMIT_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly StrictCanonicalParser $parser,
        private readonly FiscalIntegrityProvider $integrity,
        private readonly FiscalEventProjectionRegistry $projectionRegistry,
    ) {}

    /**
     * Ingest a single envelope into the server-side fiscal ledger.
     *
     * Returns an `IngestionResult` discriminating among the four §7.2
     * outcomes: stored (verified or quarantined-in-table) / idempotent
     * re-delivery / sequence_conflict (routed to `fiscal_event_quarantine`).
     */
    public function ingest(FiscalEventEnvelope $envelope): IngestionResult
    {
        // ---- Step 1: validate against the envelope, BEFORE any insert ----
        $serverReceivedAt = CarbonImmutable::now('UTC');

        $hashOk = $this->verifyHash($envelope);
        $parseResult = $this->parser->parse($envelope->canonicalBytes, $envelope->eventType);

        /** @var stdClass|null $prior */
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('terminal_id', $envelope->terminalId)
            ->orderByDesc('sequence_number')
            ->first();

        $linkageVerdict = $this->verifyLinkage($envelope, $prior);
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
            return $this->db->transaction(function () use ($envelope, $row, $integrityStatus, $exceptionClass, $serverReceivedAt, $payloadParseStatus, $parseResult): IngestionResult {
                try {
                    $this->db->table('fiscal_events')->insert($row);
                } catch (QueryException $e) {
                    if (! $this->isUniqueViolation($e)) {
                        // Some other DB error — bubble out to the outer
                        // catch so it surfaces in logs without
                        // misclassifying the row as a sequence conflict.
                        throw $e;
                    }

                    // ---- Step 4: slot occupied — examine existing ----
                    return $this->handleConflict($envelope, $serverReceivedAt, $payloadParseStatus, $parseResult);
                }

                // ---- Step 3: inserted — dispatch projection rows ----
                /** @var string $fiscalEventId */
                $fiscalEventId = $row['id'];

                $this->dispatchProjections($fiscalEventId, $envelope, $integrityStatus, $exceptionClass);

                if ($integrityStatus === IntegrityStatus::Quarantined && $exceptionClass !== null) {
                    return IngestionResult::quarantined($fiscalEventId, $exceptionClass);
                }

                return IngestionResult::stored($fiscalEventId);
            });
        } catch (Throwable $e) {
            // Last-resort fail-closed guard. The fiscal_events insert path is
            // atomic at the DB layer; the only path here is "ingest workflow
            // itself threw" (e.g. registry boot-fail not yet wired into the
            // F1 try/catch). Per §7.2 line 380 + handoff §4.2 standing
            // pattern 4 the device must never be blocked — we surface a
            // structured error log and re-throw so the controller (Task 20)
            // can return a 5xx without leaking internals to the device.
            Log::critical('OutboxIngestor: unexpected exception in ingest path — fiscal row may not be persisted.', [
                'envelope_id' => $envelope->envelopeId,
                'fiscal_event_id' => $envelope->id,
                'tenant_id' => $envelope->tenantId,
                'terminal_id' => $envelope->terminalId,
                'sequence_number' => $envelope->sequenceNumber,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Step 1 — validation
    // ------------------------------------------------------------------

    private function verifyHash(FiscalEventEnvelope $envelope): bool
    {
        return $this->integrity->verify($envelope->canonicalBytes, $envelope->currentHash);
    }

    /**
     * Verify chain linkage (§7.2 line 337).
     *
     * Returns either `null` (linkage OK) or a structured reason for why
     * linkage failed. Phase 1 contract:
     *   - For a non-first event: sequence_number == prior+1 AND
     *     previous_hash == prior.current_hash. A numeric gap with valid
     *     hash linkage is STILL sequence_gap (plan §1444 invariant).
     *   - For a first event (no prior row): sequence_number == 1.
     *     `previous_hash` should equal the terminal's
     *     `fiscal_event_genesis_seed`; that column lives on the device
     *     `terminal_state` table (Task 13) only — the server has no
     *     terminal-state mirror in Phase 1, so we do not yet validate
     *     against it. **Documented follow-up: server-side genesis-seed
     *     validation requires a `terminal_state` mirror that does not yet
     *     exist; flagged for reviewers.**
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

    /**
     * §10 clock check.
     *
     * Returns `null` when clock is admissible, otherwise a structured
     * reason. Two cases — rollback vs prior, excessive drift vs server
     * time. A malformed `event_time_device` (Carbon parse failure) is
     * defensively treated as admissible: the StrictCanonicalParser also
     * enforces the ISO-8601 format on the canonical bytes, so a
     * malformed timestamp here would already have been caught at the
     * parse layer. Belt-and-braces — never throw out of the clock check.
     */
    private function verifyClock(FiscalEventEnvelope $envelope, ?stdClass $prior, CarbonImmutable $serverReceivedAt): ?string
    {
        try {
            $eventTime = CarbonImmutable::parse($envelope->eventTimeDevice);
        } catch (Throwable) {
            return null; // malformed timestamp is the parser's anomaly, not the clock check's
        }

        if ($prior !== null) {
            try {
                $priorTime = CarbonImmutable::parse(
                    is_string($prior->event_time_device)
                        ? $prior->event_time_device
                        : (string) $prior->event_time_device,
                );
                if ($eventTime->lessThan($priorTime)) {
                    return sprintf(
                        'time_anomaly:rollback,prior=%s,current=%s',
                        $priorTime->toIso8601String(),
                        $eventTime->toIso8601String(),
                    );
                }
            } catch (Throwable) {
                // Prior row's timestamp unparseable — skip the rollback check.
            }
        }

        $drift = abs($serverReceivedAt->getTimestamp() - $eventTime->getTimestamp());
        if ($drift > self::CLOCK_DRIFT_LIMIT_SECONDS) {
            return sprintf(
                'time_anomaly:excessive_drift,drift_seconds=%d,limit=%d',
                $drift,
                self::CLOCK_DRIFT_LIMIT_SECONDS,
            );
        }

        return null;
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
    // Step 2 — INSERT row build + insert
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
     * (Task 23 — not yet implemented) per pending row, via
     * `DB::afterCommit()` so an aborted T1 produces no spurious jobs.
     */
    private function dispatchProjections(
        string $fiscalEventId,
        FiscalEventEnvelope $envelope,
        IntegrityStatus $integrityStatus,
        ?IntegrityExceptionClass $exceptionClass,
    ): void {
        if ($exceptionClass === IntegrityExceptionClass::CanonicalParseFailure) {
            // §7.5 suppression — no trusted payload to project.
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

        try {
            $activeProjectors = $this->projectionRegistry->activeProjectorsFor($eventModel);
        } catch (Throwable $e) {
            // Handoff §4.2 standing pattern 4 — fail-closed on downstream
            // service exception. The registry already catches resolver
            // throws (Task 18 F1). A throw escaping at this layer means
            // the registry's boot-asserted invariants caught a config
            // bug (Task 18 F2/F3) — log + continue with empty active set;
            // the row is still admitted.
            Log::error('OutboxIngestor: FiscalEventProjectionRegistry threw — failing closed (empty active projector set).', [
                'fiscal_event_id' => $fiscalEventId,
                'event_type' => $envelope->eventType->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

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
        // pending row. Task 23 owns the job class; until it ships, the
        // hook is wired but does nothing (no job class to dispatch).
        // Wiring it now guarantees that when Task 23 lands, the closure
        // body changes inside a single class — the ingest path doesn't
        // shift.
        $rowsForDispatch = $pendingRows;
        DB::afterCommit(static function () use ($rowsForDispatch): void {
            // TODO(Task 23): replace this closure body with `dispatch(new
            // ApplyFiscalEventProjectionJob($row['id']))` per pending
            // row. Until that job class exists, the closure is a no-op —
            // the dispatch site is wired so the rest of the ingestor
            // contract is observable in tests today.
            unset($rowsForDispatch);
        });
    }

    // ------------------------------------------------------------------
    // Step 4 — conflict handling
    // ------------------------------------------------------------------

    private function handleConflict(
        FiscalEventEnvelope $envelope,
        CarbonImmutable $serverReceivedAt,
        PayloadParseStatus $payloadParseStatus,
        ParseResult $parseResult,
    ): IngestionResult {
        /** @var stdClass|null $existing */
        $existing = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('terminal_id', $envelope->terminalId)
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
        $existingBytes = is_string($existing->canonical_bytes) ? $existing->canonical_bytes : (string) $existing->canonical_bytes;
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
            reason: $this->buildConflictReason($envelope, $existingId, $existingHash, $existingBytes),
            payloadParseStatus: $payloadParseStatus,
            parseResult: $parseResult,
        );
    }

    private function buildConflictReason(
        FiscalEventEnvelope $envelope,
        string $existingId,
        string $existingHash,
        string $existingBytes,
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

        $row = [
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
            'integrity_exception_class' => IntegrityExceptionClass::SequenceConflict->value,
            'integrity_exception_reason' => $reason,
            'conflicting_event_id' => $conflictingEventId,
            'server_received_at' => $serverReceivedAt->toDateTimeString(),
        ];

        $this->db->table('fiscal_event_quarantine')->insert($row);

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
     * Detect a unique-constraint violation in a way that works on both PG
     * (SQLSTATE 23505) and SQLite (SQLSTATE 23000 with driver code 19 +
     * "UNIQUE constraint failed" in the message). Laravel preserves both
     * states; checking the SQLSTATE class plus a substring is the
     * portable detection pattern.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23505') {
            return true; // PostgreSQL unique_violation
        }
        if ($sqlState === '23000') {
            // Generic integrity constraint — SQLite's "UNIQUE constraint
            // failed" surfaces here. Substring-match the message to avoid
            // mis-classifying a NOT NULL violation as a sequence conflict.
            return str_contains($e->getMessage(), 'UNIQUE constraint failed')
                || str_contains($e->getMessage(), 'Duplicate entry');
        }

        return false;
    }
}
