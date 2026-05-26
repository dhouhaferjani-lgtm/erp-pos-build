<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use InvalidArgumentException;

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
 *     `event_time_device`, `business_date`) are **regex-validated** by the
 *     `fromArray()` named constructor (Task 19 round-2 T19-B4). The
 *     OutboxIngestor's boundary additionally re-checks via
 *     `assertWireShape()` so a malformed envelope routes to
 *     `fiscal_event_quarantine` with class `malformed_envelope` instead of
 *     surfacing as a PG CHECK constraint violation deep inside the DB
 *     layer.
 *
 * **Why writable properties (not readonly).** Test fixtures occasionally
 * need to mutate a single field after construction (e.g. flip `currentHash`
 * to provoke a `canonical_hash_mismatch`). Production callers create the
 * DTO once at the controller layer and pass it through unchanged. PHPStan
 * still type-checks every assignment via the property type pins.
 */
final class FiscalEventEnvelope
{
    /** Lowercase 64-hex (SHA-256). Compiled once. */
    public const HEX_64_REGEX = '/^[0-9a-f]{64}$/';

    /** RFC-4122 UUID v1-5; lowercase only (spec §4). */
    public const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** ISO-8601 `YYYY-MM-DDTHH:MM:SSZ` — UTC seconds, no fractional, no offset variant. */
    public const ISO_8601_UTC_SECONDS_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /** ISO-8601 calendar date `YYYY-MM-DD`. */
    public const ISO_8601_DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';

    /** Fiscal chain contexts introduced by the Z-report rebuild. */
    public const CHAIN_CONTEXTS = [
        'operational',
        'z_session',
        'training_operational',
        'training_z_session',
    ];

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
        public string $chainContext,
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

    /**
     * Build a `FiscalEventEnvelope` from a typed wire array AND assert every
     * free-form string field's shape upfront (Task 19 round-2 T19-B4).
     *
     * Hashes must be 64-char lowercase hex; UUIDs must be lowercase
     * canonical form; `event_time_device` must be ISO-8601 UTC-seconds
     * (`YYYY-MM-DDTHH:MM:SSZ`); `business_date` must be `YYYY-MM-DD`.
     *
     * Throws `InvalidArgumentException` with a specific message naming the
     * bad field. Callers (controller + ingestor) translate this into a
     * `malformed_envelope` quarantine row or a 422 response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $envelopeId = FiscalPayloadArrayGuards::requireString($data, 'envelope_id');
        $idempotencyKey = FiscalPayloadArrayGuards::requireString($data, 'idempotency_key');
        $payloadVersion = FiscalPayloadArrayGuards::requireInt($data, 'payload_version');
        $id = FiscalPayloadArrayGuards::requireString($data, 'id');
        $tenantId = FiscalPayloadArrayGuards::requireString($data, 'tenant_id');
        $companyId = FiscalPayloadArrayGuards::requireString($data, 'company_id');
        $terminalId = FiscalPayloadArrayGuards::requireString($data, 'terminal_id');
        $operatorId = FiscalPayloadArrayGuards::requireString($data, 'operator_id');
        $eventTypeRaw = FiscalPayloadArrayGuards::requireString($data, 'event_type');
        $eventVersion = FiscalPayloadArrayGuards::requireInt($data, 'event_version');
        $signatureVersion = FiscalPayloadArrayGuards::requireString($data, 'signature_version');
        $sequenceNumber = FiscalPayloadArrayGuards::requireInt($data, 'sequence_number');
        $eventTimeDevice = FiscalPayloadArrayGuards::requireString($data, 'event_time_device');
        $businessDate = FiscalPayloadArrayGuards::requireString($data, 'business_date');
        $chainContext = FiscalPayloadArrayGuards::requireString($data, 'chain_context');
        $lastServerTimeSeen = FiscalPayloadArrayGuards::optionalString($data, 'last_server_time_seen');
        $referenceEventId = FiscalPayloadArrayGuards::optionalString($data, 'reference_event_id');
        $referenceDocumentId = FiscalPayloadArrayGuards::optionalString($data, 'reference_document_id');
        $sourceEventClass = FiscalPayloadArrayGuards::optionalString($data, 'source_event_class');
        $sourceEventId = FiscalPayloadArrayGuards::optionalString($data, 'source_event_id');
        $previousHash = FiscalPayloadArrayGuards::requireString($data, 'previous_hash');
        $currentHash = FiscalPayloadArrayGuards::requireString($data, 'current_hash');
        $canonicalBytes = FiscalPayloadArrayGuards::requireString($data, 'canonical_bytes');

        // Resolve enum from value — throws ValueError if unknown.
        try {
            $eventType = FiscalEventType::from($eventTypeRaw);
        } catch (\ValueError $e) {
            throw new InvalidArgumentException(sprintf(
                'Envelope field "event_type" must be a known FiscalEventType value; got %s.',
                var_export($eventTypeRaw, true),
            ), 0, $e);
        }

        $envelope = new self(
            envelopeId: $envelopeId,
            idempotencyKey: $idempotencyKey,
            payloadVersion: $payloadVersion,
            id: $id,
            tenantId: $tenantId,
            companyId: $companyId,
            terminalId: $terminalId,
            operatorId: $operatorId,
            eventType: $eventType,
            eventVersion: $eventVersion,
            signatureVersion: $signatureVersion,
            sequenceNumber: $sequenceNumber,
            eventTimeDevice: $eventTimeDevice,
            businessDate: $businessDate,
            chainContext: $chainContext,
            lastServerTimeSeen: $lastServerTimeSeen,
            referenceEventId: $referenceEventId,
            referenceDocumentId: $referenceDocumentId,
            sourceEventClass: $sourceEventClass,
            sourceEventId: $sourceEventId,
            previousHash: $previousHash,
            currentHash: $currentHash,
            canonicalBytes: $canonicalBytes,
        );

        $envelope->assertWireShape();

        return $envelope;
    }

    /**
     * Re-assert the envelope's free-form string fields against canonical
     * regexes (Task 19 round-2 T19-B4 defense-in-depth).
     *
     * Called by `fromArray()` after construction so callers building the
     * DTO via the named constructor can't bypass the shape check; also
     * called by the OutboxIngestor at its boundary so the ingest path can
     * route a malformed envelope to `fiscal_event_quarantine` with class
     * `malformed_envelope` before the row hits PG's CHECK constraints.
     *
     * Throws `InvalidArgumentException` with a specific message naming
     * the offending field; `($field, $regex_name)` is sufficient for the
     * quarantine row's `integrity_exception_reason`.
     */
    public function assertWireShape(): void
    {
        $this->assertMatches('id', $this->id, self::UUID_REGEX, 'uuid');
        $this->assertMatches('tenant_id', $this->tenantId, self::UUID_REGEX, 'uuid');
        $this->assertMatches('company_id', $this->companyId, self::UUID_REGEX, 'uuid');
        $this->assertMatches('terminal_id', $this->terminalId, self::UUID_REGEX, 'uuid');
        $this->assertMatches('operator_id', $this->operatorId, self::UUID_REGEX, 'uuid');
        $this->assertMatches('previous_hash', $this->previousHash, self::HEX_64_REGEX, '64-char lowercase hex');
        $this->assertMatches('current_hash', $this->currentHash, self::HEX_64_REGEX, '64-char lowercase hex');
        $this->assertMatches('event_time_device', $this->eventTimeDevice, self::ISO_8601_UTC_SECONDS_REGEX, 'ISO-8601 UTC seconds (YYYY-MM-DDTHH:MM:SSZ)');
        $this->assertMatches('business_date', $this->businessDate, self::ISO_8601_DATE_REGEX, 'ISO-8601 date (YYYY-MM-DD)');
        if (! in_array($this->chainContext, self::CHAIN_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Envelope field "chain_context" must be one of [%s]; got %s.',
                implode(', ', self::CHAIN_CONTEXTS),
                var_export($this->chainContext, true),
            ));
        }

        if ($this->referenceEventId !== null) {
            $this->assertMatches('reference_event_id', $this->referenceEventId, self::UUID_REGEX, 'uuid');
        }
        if ($this->referenceDocumentId !== null) {
            $this->assertMatches('reference_document_id', $this->referenceDocumentId, self::UUID_REGEX, 'uuid');
        }
        if ($this->sourceEventId !== null) {
            $this->assertMatches('source_event_id', $this->sourceEventId, self::UUID_REGEX, 'uuid');
        }
        if ($this->lastServerTimeSeen !== null) {
            $this->assertMatches('last_server_time_seen', $this->lastServerTimeSeen, self::ISO_8601_UTC_SECONDS_REGEX, 'ISO-8601 UTC seconds (YYYY-MM-DDTHH:MM:SSZ)');
        }

        if ($this->sequenceNumber < 1) {
            throw new InvalidArgumentException(sprintf(
                'Envelope field "sequence_number" must be a positive integer; got %d.',
                $this->sequenceNumber,
            ));
        }
    }

    private function assertMatches(string $field, string $value, string $regex, string $formatName): void
    {
        if (preg_match($regex, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Envelope field "%s" must match %s; got %s.',
                $field,
                $formatName,
                var_export($value, true),
            ));
        }
    }
}
