<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

use InvalidArgumentException;

/**
 * Terminal-level fiscal configuration consumed by `ClockAnomalyDetector`
 * (Task 25, spec v7 §10).
 *
 * Phase 1 carries the two fields the §10 `business_date` rule needs — the
 * terminal's IANA fiscal timezone and the local-time cutover hour at which
 * the business day rolls over. Later phases may extend this DTO with
 * session-start/end markers, day-closure rules, or per-vertical
 * session-grouping. Phase 1 leaves those as future fields rather than
 * reaching for `pos_terminals` columns that do not exist yet.
 *
 * **Why the DTO instead of `pos_terminals` columns directly:** spec §10 is
 * normative-text-only on which terminal columns own these values. Phase 1
 * does not migrate `pos_terminals` to add `fiscal_timezone` /
 * `session_boundary_hour`; the DTO is the surface the engine + the verifier
 * consume, and a future migration can hydrate it from a real column set
 * without touching every caller. Treating "terminal fiscal config" as a
 * typed value-object instead of two raw scalars matches the project's
 * "Constructor injection only / typed boundaries" rule (CLAUDE.md §13).
 *
 * **Construction invariants:**
 *   - `$timezone` must be a non-empty string parseable as an IANA tz
 *     identifier (DST-aware). Bare offsets like `+02:00` are NOT accepted
 *     because they cannot resolve DST transitions, which §10 must handle.
 *   - `$sessionBoundaryHour` must be an int in `[0, 23]`. The hour is
 *     interpreted in the LOCAL fiscal timezone; e.g. `4` means the
 *     business_date rolls over at 04:00 local time (typical for late-night
 *     bars/restaurants).
 */
final readonly class TerminalFiscalConfig
{
    public function __construct(
        public string $timezone,
        public int $sessionBoundaryHour,
    ) {
        if ($timezone === '') {
            throw new InvalidArgumentException('TerminalFiscalConfig.timezone must be a non-empty IANA timezone identifier.');
        }

        // Validate IANA-parseable. DateTimeZone throws on an unknown id.
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'TerminalFiscalConfig.timezone is not a valid IANA timezone: '.var_export($timezone, true),
                previous: $e,
            );
        }

        if ($sessionBoundaryHour < 0 || $sessionBoundaryHour > 23) {
            throw new InvalidArgumentException(
                'TerminalFiscalConfig.sessionBoundaryHour must be in [0, 23]; got '.$sessionBoundaryHour,
            );
        }
    }
}
