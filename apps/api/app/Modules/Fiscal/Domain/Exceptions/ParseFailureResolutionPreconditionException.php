<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `ParseFailureResolutionService::resolve()` when the target
 * `fiscal_events` row does not satisfy the resume preconditions defined by
 * spec §7.5 + the Task 8 immutability trigger (`apps/api/database/migrations/
 * 2026_05_14_100002_create_fiscal_events_immutability.php` lines 154–177):
 *
 *   - the row must exist
 *   - `payload_parse_status` must be `failed`
 *   - `payload` must be NULL (write-once trigger; second write would raise)
 *   - `integrity_status` must be `quarantined`
 *   - `integrity_exception_class` must be `canonical_parse_failure`
 *
 * Raising at the service boundary (before the UPDATE is attempted) means
 * the operator gets a typed, structured failure they can act on, rather
 * than a raw PG `integrity_constraint_violation` from the trigger.
 */
final class ParseFailureResolutionPreconditionException extends RuntimeException {}
