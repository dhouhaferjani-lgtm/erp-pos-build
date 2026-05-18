<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\ClockAnomalyDetector;
use App\Modules\Fiscal\Domain\DTOs\TerminalFiscalConfig;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 25 — `ClockAnomalyDetector` (server PHP) — spec v7 §10.
 *
 * Extracted from `OutboxIngestor::verifyClock()` so the same admissibility
 * surface is also addressable from any non-ingestor caller (verifier,
 * resolver). Adds the §10 `business_date` rule that the ingestor never
 * computed inline.
 *
 * Two responsibilities:
 *   1. `isWithinTolerance()` — false on clock rollback OR excessive drift
 *      relative to `server_received_at`. Reads the drift limit from
 *      `config('fiscal.clock_drift_limit_seconds')`.
 *   2. `businessDateFor()` — assigns `business_date` from terminal fiscal
 *      timezone + session boundary; clock anomalies never move events
 *      between closure periods.
 *
 * All time-sensitive tests wrap `CarbonImmutable::setTestNow` in
 * try/finally so a failure mid-suite cannot leak a frozen clock into
 * neighbouring tests (Task 23 R3-F2 standing pattern).
 */
final class ClockAnomalyDetectorTest extends TestCase
{
    private function detector(): ClockAnomalyDetector
    {
        return new ClockAnomalyDetector;
    }

    private function tunis(): TerminalFiscalConfig
    {
        // Africa/Tunis: UTC+01:00 year-round (no DST). Predictable for
        // boundary tests without DST entanglement.
        return new TerminalFiscalConfig(timezone: 'Africa/Tunis', sessionBoundaryHour: 4);
    }

    private function paris(): TerminalFiscalConfig
    {
        // Europe/Paris: UTC+01:00 (CET) / UTC+02:00 (CEST) with DST forward
        // last Sunday of March, back last Sunday of October.
        return new TerminalFiscalConfig(timezone: 'Europe/Paris', sessionBoundaryHour: 4);
    }

    // =================================================================
    // isWithinTolerance — happy path
    // =================================================================

    public function test_returns_true_when_device_time_close_to_server_received_at(): void
    {
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: '2026-05-14T10:00:00Z',
            lastServerTimeSeen: '2026-05-14T09:59:00Z',
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_returns_true_when_no_prior_server_time_seen(): void
    {
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: '2026-05-14T10:00:00Z',
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    // =================================================================
    // isWithinTolerance — rollback (plan §1916)
    // =================================================================

    public function test_clock_rollback_is_flagged_time_anomaly(): void
    {
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: '2020-01-01T00:00:00Z',
            lastServerTimeSeen: '2026-05-14T10:00:00Z',
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_rollback_one_second_behind_prior_is_still_a_rollback(): void
    {
        // Strict less-than: even a one-second rollback flags.
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: '2026-05-14T09:59:59Z',
            lastServerTimeSeen: '2026-05-14T10:00:00Z',
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_equal_to_prior_is_not_a_rollback(): void
    {
        // The boundary itself (deviceTime == lastServerTimeSeen) is
        // admissible — equal-or-greater, not strictly-greater.
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: '2026-05-14T09:59:00Z',
            lastServerTimeSeen: '2026-05-14T09:59:00Z',
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    // =================================================================
    // isWithinTolerance — excessive drift
    // =================================================================

    public function test_excessive_drift_future_is_flagged(): void
    {
        // Drift > config limit. With default 86400s, push 7 days into the
        // future from server time.
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $deviceTime = '2026-05-21T10:00:00Z'; // +7 days

        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: $deviceTime,
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_excessive_drift_past_is_flagged(): void
    {
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $deviceTime = '2026-05-07T10:00:00Z'; // -7 days

        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: $deviceTime,
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_drift_exactly_at_tolerance_is_admissible(): void
    {
        // 86400s = drift threshold; equal is NOT excessive (strict >).
        config()->set('fiscal.clock_drift_limit_seconds', 86400);

        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $deviceTime = '2026-05-15T10:00:00Z'; // exactly +86400s

        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: $deviceTime,
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_drift_one_second_over_tolerance_is_flagged(): void
    {
        config()->set('fiscal.clock_drift_limit_seconds', 86400);

        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $deviceTime = '2026-05-15T10:00:01Z'; // +86401s

        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: $deviceTime,
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_drift_limit_is_config_readable(): void
    {
        // A 1-second drift limit makes a 5-second future-shift trip the
        // anomaly path.
        config()->set('fiscal.clock_drift_limit_seconds', 1);

        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $deviceTime = '2026-05-14T10:00:05Z'; // +5s drift

        $this->assertFalse($this->detector()->isWithinTolerance(
            deviceTime: $deviceTime,
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    // =================================================================
    // isWithinTolerance — malformed input (defensive)
    // =================================================================

    public function test_malformed_device_time_returns_true_defers_to_parser(): void
    {
        // Per OutboxIngestor `verifyClock` carryforward: a malformed
        // event_time_device is the parser's anomaly, not the clock
        // check's — admissible here, the parser quarantines it.
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: 'not-a-timestamp',
            lastServerTimeSeen: null,
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    public function test_malformed_last_server_time_seen_skips_rollback_check(): void
    {
        // Bad prior timestamp is treated as "unknown prior" so the
        // rollback branch is skipped; the drift branch still fires.
        $serverReceivedAt = CarbonImmutable::parse('2026-05-14T10:00:00Z');
        $this->assertTrue($this->detector()->isWithinTolerance(
            deviceTime: '2026-05-14T10:00:00Z',
            lastServerTimeSeen: 'not-a-timestamp',
            serverReceivedAt: $serverReceivedAt,
        ));
    }

    // =================================================================
    // businessDateFor (plan §1922 — clock anomaly never moves an event
    // between closure periods).
    // =================================================================

    public function test_business_date_uses_terminal_fiscal_timezone_not_server_received_at(): void
    {
        // 22:00 UTC on May 14 → 23:00 Tunis time on May 14 → post-cutover
        // → business_date = '2026-05-14'.
        $this->assertSame(
            '2026-05-14',
            $this->detector()->businessDateFor('2026-05-14T22:00:00Z', $this->tunis()),
        );
    }

    public function test_business_date_post_cutover_in_local_timezone_is_today(): void
    {
        // 10:00 UTC on May 14 → 11:00 Tunis time on May 14 →
        // post-cutover (>= 04:00) → business_date = '2026-05-14'.
        $this->assertSame(
            '2026-05-14',
            $this->detector()->businessDateFor('2026-05-14T10:00:00Z', $this->tunis()),
        );
    }

    public function test_business_date_pre_cutover_rolls_back_to_previous_calendar_day(): void
    {
        // 02:00 UTC on May 14 → 03:00 Tunis time on May 14 → pre-cutover
        // (< 04:00) → business_date = '2026-05-13' (previous calendar day).
        $this->assertSame(
            '2026-05-13',
            $this->detector()->businessDateFor('2026-05-14T02:00:00Z', $this->tunis()),
        );
    }

    public function test_business_date_at_midnight_local_time_rolls_back_when_cutover_nonzero(): void
    {
        // 23:00 UTC on May 14 → 00:00 Tunis time on May 15 → pre-cutover
        // (< 04:00) → business_date = '2026-05-14' (previous local calendar day).
        $this->assertSame(
            '2026-05-14',
            $this->detector()->businessDateFor('2026-05-14T23:00:00Z', $this->tunis()),
        );
    }

    public function test_business_date_exactly_at_cutover_hour_is_today(): void
    {
        // 03:00 UTC on May 14 → 04:00 Tunis time on May 14 → boundary
        // (>= 04:00) → business_date = '2026-05-14' (current local day).
        $this->assertSame(
            '2026-05-14',
            $this->detector()->businessDateFor('2026-05-14T03:00:00Z', $this->tunis()),
        );
    }

    public function test_business_date_session_boundary_zero_means_calendar_day_only(): void
    {
        // With sessionBoundaryHour=0, business_date == local calendar day
        // always (no roll-back). 23:00 Tunis local on May 14 stays May 14;
        // 00:00:01 local on May 15 becomes May 15.
        $config = new TerminalFiscalConfig(timezone: 'Africa/Tunis', sessionBoundaryHour: 0);

        // 22:00 UTC = 23:00 Tunis on May 14 (≥ 0, current day).
        $this->assertSame(
            '2026-05-14',
            $this->detector()->businessDateFor('2026-05-14T22:00:00Z', $config),
        );
        // 23:00:01 UTC = 00:00:01 Tunis on May 15 (≥ 0, current day).
        $this->assertSame(
            '2026-05-15',
            $this->detector()->businessDateFor('2026-05-14T23:00:01Z', $config),
        );
    }

    public function test_business_date_handles_dst_spring_forward(): void
    {
        // Paris DST forward 2026: last Sunday of March = March 29, 2026.
        // 01:00 UTC on 2026-03-29 → 02:00 CET, then immediately 03:00 CEST
        // (the "skipped" 02:00–03:00 local hour).
        // Pick a moment on either side of the forward jump:
        //   - 00:30 UTC = 01:30 CET (pre-DST), pre-cutover (<04:00) →
        //     business_date = '2026-03-28'.
        //   - 02:00 UTC = 04:00 CEST (post-DST), at-cutover →
        //     business_date = '2026-03-29'.
        $this->assertSame(
            '2026-03-28',
            $this->detector()->businessDateFor('2026-03-29T00:30:00Z', $this->paris()),
        );
        $this->assertSame(
            '2026-03-29',
            $this->detector()->businessDateFor('2026-03-29T02:00:00Z', $this->paris()),
        );
    }

    public function test_business_date_handles_dst_fall_back(): void
    {
        // Paris DST back 2026: last Sunday of October = October 25, 2026.
        // The DST jump-back happens at 01:00 UTC (= 03:00 CEST → 02:00 CET);
        // the local clock-hour 02:00–03:00 occurs twice.
        //   - 03:30 UTC = 04:30 CET (post-fallback, post-cutover) →
        //     business_date = '2026-10-25'.
        //   - 02:30 UTC = 03:30 CET (post-fallback, pre-cutover <04:00) →
        //     business_date = '2026-10-24' (rolled back).
        //   - 00:30 UTC = 02:30 CEST (pre-fallback, pre-cutover) →
        //     business_date = '2026-10-24'.
        // The pre/post-fallback distinction is invisible at the
        // business_date layer (both 02:30 local occurrences are pre-cutover)
        // — what matters is the LOCAL hour after the IANA DST rules apply.
        $this->assertSame(
            '2026-10-25',
            $this->detector()->businessDateFor('2026-10-25T03:30:00Z', $this->paris()),
        );
        $this->assertSame(
            '2026-10-24',
            $this->detector()->businessDateFor('2026-10-25T02:30:00Z', $this->paris()),
        );
        $this->assertSame(
            '2026-10-24',
            $this->detector()->businessDateFor('2026-10-25T00:30:00Z', $this->paris()),
        );
    }

    public function test_business_date_rejects_malformed_device_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->businessDateFor('not-a-timestamp', $this->tunis());
    }

    // =================================================================
    // TerminalFiscalConfig — construction invariants
    // =================================================================

    public function test_terminal_fiscal_config_rejects_empty_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TerminalFiscalConfig(timezone: '', sessionBoundaryHour: 0);
    }

    public function test_terminal_fiscal_config_rejects_invalid_iana_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TerminalFiscalConfig(timezone: 'Not/A_Real_Zone', sessionBoundaryHour: 0);
    }

    public function test_terminal_fiscal_config_rejects_out_of_range_boundary_hour(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TerminalFiscalConfig(timezone: 'UTC', sessionBoundaryHour: 24);
    }

    public function test_terminal_fiscal_config_rejects_negative_boundary_hour(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TerminalFiscalConfig(timezone: 'UTC', sessionBoundaryHour: -1);
    }
}
