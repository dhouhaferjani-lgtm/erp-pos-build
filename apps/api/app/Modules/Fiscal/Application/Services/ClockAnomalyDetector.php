<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\TerminalFiscalConfig;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Server-side clock / time anomaly detector — spec v7 §10, plan §1890–1948 (Task 25).
 *
 * Extracted from `OutboxIngestor::verifyClock()` (Task 19) so the same
 * admissibility surface — the §10 normative rules — is addressable from any
 * non-ingestor caller (verifier, parse-failure resolver) without dragging
 * the ingestor surface in. Mirrors the round-2 extraction pattern Task 24
 * applied to `FiscalPayloadConstraintValidator` (extracted from
 * `StrictCanonicalParser` so the resolver and the parser share one
 * validation contract).
 *
 * **Two responsibilities — both per spec §10:**
 *
 *   1. `isWithinTolerance()` — `event_time_device` is *admissible* relative
 *      to:
 *        a. `lastServerTimeSeen` — strict rollback (deviceTime <
 *           lastServerTimeSeen) flags `time_anomaly`.
 *        b. `serverReceivedAt` — drift `|deviceTime - serverReceivedAt|`
 *           exceeds `config('fiscal.clock_drift_limit_seconds')` flags
 *           `time_anomaly`.
 *      Out-of-tolerance is **accepted, not blocked** (the ingestor still
 *      writes the row with `integrity_exception_class = time_anomaly`).
 *
 *   2. `businessDateFor()` — assigns `business_date` from the terminal's
 *      configured fiscal timezone + session boundary. **NEVER** from
 *      `server_received_at`; **NEVER** from the raw device time. A clock
 *      anomaly never moves an event between closure periods without an
 *      explicit correction event (§10 last sentence).
 *
 * **Design decisions:**
 *
 *   - **Stateless, pure functions** — the detector has no DB dependency.
 *     `config('fiscal.clock_drift_limit_seconds')` is the only side input
 *     and the container resolves it per-call. The container auto-resolves
 *     this class with zero constructor args (Laravel binds it implicitly);
 *     no provider binding needed.
 *   - **Defensive on malformed input** — a malformed `event_time_device`
 *     in `isWithinTolerance()` returns `true` (admissible) because the
 *     `StrictCanonicalParser` is the authoritative timestamp-format
 *     gate; throwing here would block ingestion of a row the parser is
 *     responsible for quarantining. `businessDateFor()` cannot fall
 *     back like that — it must produce a date or fail loudly — so a
 *     malformed input there throws `InvalidArgumentException` (the
 *     caller, the device, has the guardrails to never pass it).
 *   - **DST-correct** — `Africa/Tunis` and `Europe/Paris` are exercised
 *     in tests. Conversion uses `CarbonImmutable::setTimezone($iana)`
 *     which honours DST offsets.
 */
final class ClockAnomalyDetector
{
    /**
     * Default clock drift acceptance window — 24h. Matches
     * `OutboxIngestor::clockDriftLimitSeconds()` round-1 default
     * (apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:533).
     * Operators override per-deployment via `config/fiscal.php`.
     */
    private const DEFAULT_CLOCK_DRIFT_LIMIT_SECONDS = 24 * 60 * 60;

    /**
     * Returns `true` when `deviceTime` is admissible — neither a clock
     * rollback vs `lastServerTimeSeen` nor an excessive drift vs
     * `serverReceivedAt`. Returns `false` when the §10 `time_anomaly`
     * class should attach.
     *
     * A malformed `deviceTime` returns `true` defensively (the parser is
     * the format authority); a malformed `lastServerTimeSeen` simply
     * skips the rollback branch (drift still evaluated).
     *
     * Drift comparison is strict `>` so the configured limit is the
     * inclusive admissibility boundary.
     */
    public function isWithinTolerance(
        string $deviceTime,
        ?string $lastServerTimeSeen,
        CarbonImmutable $serverReceivedAt,
    ): bool {
        $eventTime = $this->tryParse($deviceTime);
        if ($eventTime === null) {
            // Malformed timestamp is the parser's anomaly, not the clock
            // check's — admissible here (StrictCanonicalParser will
            // quarantine the row via `canonical_parse_failure`).
            return true;
        }

        if ($lastServerTimeSeen !== null) {
            $priorTime = $this->tryParse($lastServerTimeSeen);
            if ($priorTime !== null && $eventTime->lessThan($priorTime)) {
                return false;
            }
        }

        $limit = $this->clockDriftLimitSeconds();
        $drift = abs($serverReceivedAt->getTimestamp() - $eventTime->getTimestamp());

        return $drift <= $limit;
    }

    /**
     * Returns the `YYYY-MM-DD` business_date for an event whose
     * `event_time_device` is `$deviceTime`, given the terminal's
     * fiscal timezone + session boundary.
     *
     * Algorithm:
     *   1. Parse `$deviceTime` as a UTC timestamp.
     *   2. Convert to the terminal's IANA timezone (DST-aware).
     *   3. If local hour < `sessionBoundaryHour`, business_date is the
     *      previous local calendar day; else current local calendar day.
     *   4. Format as `YYYY-MM-DD`.
     *
     * Edge cases covered by `ClockAnomalyDetectorTest`:
     *   - DST forward / fall back transitions (Europe/Paris)
     *   - Midnight local boundary with sessionBoundaryHour > 0
     *   - sessionBoundaryHour = 0 (calendar-day equivalence)
     *
     * **Never throws on a clock anomaly** — only on a malformed
     * `$deviceTime` (which is the caller's invariant; the device
     * envelope's wire-shape check enforces ISO-8601 second precision
     * before the ingestor reaches this code).
     *
     * @throws InvalidArgumentException when `$deviceTime` is not parseable
     */
    public function businessDateFor(string $deviceTime, TerminalFiscalConfig $config): string
    {
        $eventTime = $this->tryParse($deviceTime);
        if ($eventTime === null) {
            throw new InvalidArgumentException(
                'ClockAnomalyDetector::businessDateFor — unparseable deviceTime: '.var_export($deviceTime, true),
            );
        }

        // CarbonImmutable preserves the original instant; setTimezone()
        // rotates the *presentation* (year/month/day/hour) into the
        // target IANA zone with DST applied.
        $local = $eventTime->setTimezone($config->timezone);

        if ($local->hour < $config->sessionBoundaryHour) {
            $local = $local->subDay();
        }

        return $local->format('Y-m-d');
    }

    /**
     * Format the structured `integrity_exception_reason` string for a
     * time-anomaly verdict, given the same inputs the detector consumed.
     * Owned here (round-2 Opus F2/F5 closure) so the format AND the
     * threshold are colocated — a future change to either is a single-file
     * edit that cannot drift between the decision site and the reason
     * site.
     *
     * Always returns a non-empty string. The caller should have checked
     * `isWithinTolerance()` first and only invoke this method when that
     * returned `false`. Calling it on an admissible row produces the
     * `excessive_drift` shape with `drift_seconds=<small_int>,limit=<config>`
     * which is harmless but semantically wrong — the caller's
     * pre-condition is "you saw a `false` from isWithinTolerance".
     *
     * Reason shapes (preserved from `OutboxIngestor::verifyClock()` exactly,
     * so the verifier + existing 20 OutboxIngestorTest cases continue to
     * pass):
     *
     *   - `time_anomaly:rollback,prior=<ISO>,current=<ISO>` — when
     *     `deviceTime < lastServerTimeSeen` (strict less-than).
     *   - `time_anomaly:excessive_drift,drift_seconds=<int>,limit=<int>` —
     *     when `|deviceTime - serverReceivedAt| > limit`.
     *   - `time_anomaly:detector_flagged_unparseable_event_time_device` —
     *     defensive: detector returned false but the caller cannot reparse
     *     `deviceTime` (impossible in steady state; the detector's
     *     malformed-input branch returns true).
     *
     * Rollback takes precedence when both `priorTime` is parseable and
     * `eventTime < priorTime`. Drift is the fallback. Branch ordering
     * mirrors the round-1 `OutboxIngestor::verifyClock()` priority.
     */
    public function formatTimeAnomalyReason(
        string $deviceTime,
        ?string $lastServerTimeSeen,
        CarbonImmutable $serverReceivedAt,
    ): string {
        $eventTime = $this->tryParse($deviceTime);
        if ($eventTime === null) {
            // Detector said "not admissible" yet deviceTime is unparseable —
            // impossible in steady state (the detector's malformed branch
            // returns true). Defensive return so we never crash on a
            // future detector evolution that admits malformed input.
            return 'time_anomaly:detector_flagged_unparseable_event_time_device';
        }

        // Rollback branch — strict less-than vs prior. Only emit when the
        // prior row's timestamp parses cleanly; otherwise drift is the
        // only remaining cause.
        if ($lastServerTimeSeen !== null) {
            $priorTime = $this->tryParse($lastServerTimeSeen);
            if ($priorTime !== null && $eventTime->lessThan($priorTime)) {
                return sprintf(
                    'time_anomaly:rollback,prior=%s,current=%s',
                    $priorTime->toIso8601String(),
                    $eventTime->toIso8601String(),
                );
            }
        }

        $limit = $this->clockDriftLimitSeconds();
        $drift = abs($serverReceivedAt->getTimestamp() - $eventTime->getTimestamp());

        return sprintf(
            'time_anomaly:excessive_drift,drift_seconds=%d,limit=%d',
            $drift,
            $limit,
        );
    }

    /**
     * Best-effort Carbon parse — returns `null` on failure rather than
     * throwing so the caller can branch defensively. UTC is the
     * normative interpretation per spec §4 / §6.
     */
    private function tryParse(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function clockDriftLimitSeconds(): int
    {
        $configured = config('fiscal.clock_drift_limit_seconds', self::DEFAULT_CLOCK_DRIFT_LIMIT_SECONDS);

        return is_int($configured) ? $configured : (int) $configured;
    }
}
