<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;

/**
 * Typed transport DTO for a fiscal event arriving at the server (spec v7 §7.1).
 *
 * The device posts an outer wire envelope
 * `{ envelope_id, type: 'FISCAL_EVENT', payload_version, payload, idempotency_key }`;
 * the `payload` portion carries the fields enumerated below — exactly the
 * fourteen canonical envelope keys per spec §4 PLUS the verbatim
 * `canonical_bytes`, `current_hash`, and the outer transport identifiers
 * required by §7.2's validate-then-insert flow.
 *
 * The DTO is **constructed by the controller (Task 20)** from the validated
 * request input. The OutboxIngestor (this task) accepts it as-is and never
 * widens the contract — any field that arrives malformed at the controller
 * layer must be rejected there, not silently coerced. The DTO carries
 * typed-only fields (`int`, `string`, enum) so PHPStan level 8 catches
 * shape drift at the call site.
 *
 * **Carry-forward standing patterns (handoff §4.2):**
 *   - No `(type) $array['key']` casts — fields are typed at construction;
 *     callers use `FiscalPayloadArrayGuards` if reading from arrays (Task 14
 *     BLOCKER pattern carried forward).
 *   - Free-form string fields (`previous_hash`, `current_hash`,
 *     `event_time_device`, `business_date`) are **regex-validated** at the
 *     controller boundary (Task 20) using the same canonical regexes the
 *     StrictCanonicalParser uses on the canonical bytes themselves. The DTO
 *     itself is a pure transport carrier; format invariants are pre-validated
 *     upstream so the OutboxIngestor can trust the inputs.
 *
 * **Why writable properties (not readonly).** Test fixtures occasionally
 * need to mutate a single field after construction (e.g. flip `currentHash`
 * to provoke a `canonical_hash_mismatch`). Production callers create the
 * DTO once at the controller layer and pass it through unchanged. PHPStan
 * still type-checks every assignment via the property type pins.
 */
final class FiscalEventEnvelope
{
    public function __construct(
        /** Wire-envelope id (the controller-generated request envelope id; not a fiscal_events.id). */
        public string $envelopeId,
        /** Wire-envelope idempotency key — spec §7.1: (terminal_id, sequence_number) stringified. */
        public string $idempotencyKey,
        /** Wire-envelope payload version — spec §7.1. */
        public int $payloadVersion,
        // ---- the inner fiscal event payload (canonical envelope §4) ----
        /** The device-claimed fiscal_events.id (UUID). The server respects this so chains stay forensically linkable. */
        public string $id,
        public string $tenantId,
        public string $companyId,
        public string $terminalId,
        public string $operatorId,
        public FiscalEventType $eventType,
        public int $eventVersion,
        public string $signatureVersion,
        public int $sequenceNumber,
        /** ISO 8601 UTC seconds — `YYYY-MM-DDTHH:MM:SSZ`. */
        public string $eventTimeDevice,
        /** ISO 8601 date — `YYYY-MM-DD`. */
        public string $businessDate,
        /** Server-time seen on device — nullable; pre-session-1 events may not carry one. */
        public ?string $lastServerTimeSeen,
        public ?string $referenceEventId,
        public ?string $referenceDocumentId,
        public ?string $sourceEventClass,
        public ?string $sourceEventId,
        /** 64-char lowercase hex. */
        public string $previousHash,
        /** 64-char lowercase hex — MUST equal SHA-256(canonicalBytes); the §7.2 hash_ok check. */
        public string $currentHash,
        /** Verbatim canonical bytes per spec §4 (JCS, no insignificant whitespace). */
        public string $canonicalBytes,
    ) {}
}
