<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `QuarantineIncidentResolutionService::resolve()` when the target
 * `fiscal_event_quarantine` row already carries a resolution stamp.
 *
 * ES-17 clause 17-G: re-stamping must "either no-op or refuse; it must not
 * silently overwrite the original `resolved_by`". This service refuses, so the
 * second operator is TOLD the incident was already adjudicated and by whom,
 * rather than quietly becoming the person of record for a decision someone else
 * made.
 *
 * **Correction (M3b round 1, F-3).** The approved contract asserted that
 * `resolved_by` "is the audit fact the NF525 §8 export publishes". It is not.
 * `Nf525DataProvider::buildQuarantineSection()` selects and emits `resolved_at`
 * only; `resolved_by` appears nowhere in that provider. What §8 publishes is
 * THAT an incident was adjudicated and WHEN — never BY WHOM. The adjudicator's
 * identity survives only in the raw `fiscal_event_quarantine.resolved_by`
 * column, which is precisely why overwriting it must be refused rather than
 * treated as recoverable from the export. Whether §8 SHOULD carry `resolved_by`
 * is an export-schema question outside 17-A…17-G; it is an open owner question
 * on this wave's ledger, not a change to take here.
 */
final class QuarantineAlreadyResolvedException extends RuntimeException {}
