<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `QuarantineIncidentResolutionService::resolve()` when the target
 * `fiscal_event_quarantine` row already carries a resolution stamp.
 *
 * ES-17 clause 17-G: re-stamping must "either no-op or refuse; it must not
 * silently overwrite the original `resolved_by`, which is the audit fact the
 * §8 export publishes" (`Nf525DataProvider.php:1603-1605`). This service
 * refuses, so the second operator is TOLD the incident was already adjudicated
 * and by whom, rather than quietly becoming the person of record for a
 * decision someone else made.
 */
final class QuarantineAlreadyResolvedException extends RuntimeException {}
