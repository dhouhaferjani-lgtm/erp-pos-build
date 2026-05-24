<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum FiscalIntegrityAnomaly: string
{
    case CanonicalHashMismatch = 'canonical_hash_mismatch';
    case CanonicalParseFailure = 'canonical_parse_failure';
    case DeviceTimeAnomaly = 'device_time_anomaly';
    case SequenceGap = 'sequence_gap';
    case SignatureInvalid = 'signature_invalid';
}
