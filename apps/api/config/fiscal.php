<?php

declare(strict_types=1);

/*
 * Fiscal Event Engine — Phase 1 configuration.
 *
 * Spec: docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md
 *
 * These knobs are surfaced via `config('fiscal.*')` so per-deployment
 * overrides (e.g. multi-day offline batches authored on devices with a
 * slightly drifted clock) can adjust the conservative defaults without
 * code changes.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Clock-drift acceptance window
    |--------------------------------------------------------------------------
    |
    | §10 "excessive drift" threshold — `abs(event_time_device -
    | server_received_at)`. Beyond this we flag `time_anomaly` (the row is
    | still accepted; the device is never blocked).
    |
    | 24 hours (86400 s) covers worst-case NTP-drifted devices + timezone
    | surprises. Legitimate multi-day offline batches all hit
    | `server_received_at` close to "now" (the sync time), so drift is
    | naturally bounded by how long a single event was cached on-device.
    | Operators running highly-offline fleets (rural cellular, mobile
    | service vans) can widen this knob without rebuilding.
    |
    | Task 19 round-2 P1 (T19-P1) — was a hardcoded constant; now config-
    | readable so legitimate offline batches don't trip the flag.
    */

    'clock_drift_limit_seconds' => env('FISCAL_CLOCK_DRIFT_LIMIT_SECONDS', 24 * 60 * 60),
];
