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

    public function isAdmissibleToLedger(): bool
    {
        return $this !== self::SequenceConflict;
    }
}
