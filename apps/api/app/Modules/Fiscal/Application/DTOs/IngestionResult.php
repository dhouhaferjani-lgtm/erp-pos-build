<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\DTOs;

use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;

/**
 * Outcome of `OutboxIngestor::ingest()` (spec v7 §7.2).
 *
 * Four call-sites in §7.2's flow map to four named factories:
 *
 *  - **`stored()`** — Step 3 happy path: a row was returned by
 *    `INSERT … ON CONFLICT DO NOTHING RETURNING id`; the event is in
 *    `fiscal_events` (either `verified` or in-table-quarantined per §8).
 *  - **`quarantined()`** — Step 3 path where the row was admitted as
 *    a quarantine (`canonical_hash_mismatch`, `canonical_parse_failure`,
 *    `time_anomaly`, `sequence_gap`). Carries the exception class so the
 *    controller can surface it in the per-envelope response.
 *  - **`idempotent()`** — Step 4 path where the conflicting envelope
 *    matched the existing row byte-for-byte (genuine idempotent
 *    re-delivery). `stored = false`; the event already lives.
 *  - **`sequenceConflict()`** — Step 4 path where a DIFFERENT envelope
 *    claimed an already-occupied slot. The envelope is now in
 *    `fiscal_event_quarantine` (§8); `fiscalEventId` is null because
 *    the event physically cannot enter `fiscal_events`.
 *
 * `stored` is a single boolean signal — "the event was newly inserted
 * into `fiscal_events`" — so callers (Task 20 + Task 23) can branch on
 * whether to dispatch projection jobs without inspecting all four
 * combinations. Idempotent re-delivery returns `stored = false` so
 * projections are not re-dispatched (§7.2 line 367).
 */
final readonly class IngestionResult
{
    private function __construct(
        public bool $stored,
        public ?string $fiscalEventId,
        public bool $sequenceConflict,
        public ?IntegrityExceptionClass $exceptionClass,
        public ?string $rejectionCode = null,
    ) {}

    /**
     * Step 3 happy path — verified row admitted to `fiscal_events`.
     */
    public static function stored(string $fiscalEventId): self
    {
        return new self(
            stored: true,
            fiscalEventId: $fiscalEventId,
            sequenceConflict: false,
            exceptionClass: null,
            rejectionCode: null,
        );
    }

    /**
     * Step 3 path — quarantined-in-table (admissible-but-flagged) row admitted to
     * `fiscal_events`. The exception class is carried through so controllers
     * can surface it in the per-envelope response.
     */
    public static function quarantined(string $fiscalEventId, IntegrityExceptionClass $exceptionClass): self
    {
        return new self(
            stored: true,
            fiscalEventId: $fiscalEventId,
            sequenceConflict: false,
            exceptionClass: $exceptionClass,
            rejectionCode: null,
        );
    }

    /**
     * Step 4 path — genuine idempotent re-delivery (envelope matched existing row byte-for-byte).
     */
    public static function idempotent(string $fiscalEventId): self
    {
        return new self(
            stored: false,
            fiscalEventId: $fiscalEventId,
            sequenceConflict: false,
            exceptionClass: null,
            rejectionCode: null,
        );
    }

    /**
     * Step 4 path — non-admissible sequence conflict; envelope routed to `fiscal_event_quarantine`.
     */
    public static function sequenceConflict(): self
    {
        return new self(
            stored: false,
            fiscalEventId: null,
            sequenceConflict: true,
            exceptionClass: IntegrityExceptionClass::SequenceConflict,
            rejectionCode: null,
        );
    }

    /**
     * Step 0 path (T19-B4 round-2) — the inbound envelope failed shape
     * validation at the ingestor boundary (non-hex hash, malformed UUID,
     * malformed timestamp). The row was routed to
     * `fiscal_event_quarantine` with class `malformed_envelope`; it
     * physically cannot enter `fiscal_events` because the PG CHECK
     * constraints would reject it. The controller renders a structured
     * 422 or 200-with-quarantine-receipt response depending on operator
     * policy (Task 20 contract).
     */
    public static function malformedEnvelope(): self
    {
        return new self(
            stored: false,
            fiscalEventId: null,
            sequenceConflict: false,
            exceptionClass: IntegrityExceptionClass::MalformedEnvelope,
            rejectionCode: null,
        );
    }

    public static function rejectedServerOnly(): self
    {
        return new self(
            stored: false,
            fiscalEventId: null,
            sequenceConflict: false,
            exceptionClass: null,
            rejectionCode: 'SERVER_ONLY_EVENT_TYPE',
        );
    }
}
