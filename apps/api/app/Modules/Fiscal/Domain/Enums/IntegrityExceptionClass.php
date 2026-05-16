<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum IntegrityExceptionClass: string
{
    case CanonicalHashMismatch = 'canonical_hash_mismatch';
    case CanonicalParseFailure = 'canonical_parse_failure';
    case TimeAnomaly = 'time_anomaly';
    case SequenceGap = 'sequence_gap';
    case SequenceConflict = 'sequence_conflict';
    /**
     * Envelope-shape failure detected at the ingestor boundary BEFORE any
     * chain check fires (Task 19 round-2 T19-B4). A malformed `current_hash`
     * (non-hex), malformed UUID `id`, or malformed `event_time_device`
     * cannot be admitted to `fiscal_events` (the PG CHECK constraints would
     * reject it) and is not a sequence conflict (the slot might be free),
     * so it routes to `fiscal_event_quarantine` with this class. The
     * quarantine table CHECK constraint allows this value alongside
     * `sequence_conflict` from Task 19 round-2 onward.
     */
    case MalformedEnvelope = 'malformed_envelope';

    public function isAdmissibleToLedger(): bool
    {
        return $this !== self::SequenceConflict && $this !== self::MalformedEnvelope;
    }
}
