<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use RuntimeException;

/**
 * Thrown by {@see LegacyCorrectionGuard}
 * when a legacy (non-fiscal-event) return or void is attempted against a
 * terminal whose v4 refund-authoring capability has been acknowledged
 * (v3-refund-chain-integration spec §9.1/§9.3).
 *
 * Maps to HTTP 409, error code `LEGACY_CORRECTION_RETIRED` (§9.4's typed
 * refusal — never "use the legacy path", since the legacy path IS the one
 * being retired for this terminal).
 */
final class LegacyCorrectionRetiredException extends RuntimeException
{
    public function __construct(
        public readonly string $terminalId,
    ) {
        parent::__construct(sprintf(
            'LegacyCorrectionRetired: terminal %s has acknowledged v4 refund authoring — '.
            'legacy (non-fiscal-event) returns/voids are retired for this terminal.',
            $terminalId,
        ));
    }
}
