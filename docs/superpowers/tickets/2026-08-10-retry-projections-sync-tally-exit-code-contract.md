# Ticket: `fiscal:retry-projections` — `--sync` non-retryable tally lies + exit-code-2 four-way overload (one contract, design together)

**Date:** 2026-08-10
**Source:** fiscal-pos-reviewer re-review of fix round 1, branch `codex/accounting-gaps-cghi` (ruling: MERGEABLE WITH TICKET) + new finding N1.
**Severity:** P3 operational (row state is always correct; only reporting is wrong).

## Defect 1 — `--sync` counts a non-retryable dead-letter as "applied", exit 0

`ApplyFiscalEventProjectionJob::handle()` handles `NonRetryableProjectionException` by calling `deadLetterImmediately($row, $e)` then `$this->fail($e)` then `return;` — no throw (`ApplyFiscalEventProjectionJob.php:395-413`). Under `--sync` the job was constructed directly (`RetryFiscalProjectionsCommand.php` `new ApplyFiscalEventProjectionJob($rowId)`), never dispatched, so `InteractsWithQueue::fail()` no-ops (`$this->job` is null, `InteractsWithQueue.php:53-66`) and `handle()` returns normally → `$syncAppliedCount++`, `$failureCount` untouched → console prints "synchronously applied N; 0 failures" and the command exits 0.

**Row state is CORRECT**: `deadLetterImmediately()` writes `DeadLettered` + `dead_lettered_at` + `last_error` before `fail()` is reached, so the row stays visible to `--dry-run` and the 15-minute scheduled sweep, and `Log::critical` fires. Only the tally and exit code lie.

**Reachable in the real recovery workflow, not theoretical**: the `NonRetryableProjectionException` marker is implemented by the POS refund exceptions — an operator replaying a dead-lettered refund projection hits this branch.

## Defect 2 (N1) — exit code 2 is four-way overloaded

`RetryFiscalProjectionsCommand` returns `INVALID` (2) for: bad `--event-type`, explicitly-empty filter values, unknown `--projector`, malformed `--event-id`, plus the unvisited-tenant `INVALID` from `failIfTenantFilterUnvisited()` — AND a hand-rolled `2` for "a sync replay threw and dead-lettered". A runbook keying on exit 2 cannot distinguish "you mistyped the flag" from "the replay failed". `TenantScopedCommand.php:388-393` explicitly warns about this collision class (citing `VerifyEventChainCommand` as the remap precedent). Pre-existing in kind, aggravated in degree by fix-round-1's C2/C3 validation sites.

## Why not fixed in fix round 1 (reviewer ruling)

Counting the non-retryable branch as a failure means widening the meaning of exit 2 or introducing a third code — a contract change on a command consumed by scheduler wiring, requiring the N1 overload to be resolved at the same time. A rushed edit risks a worse regression than the defect. No fiscal hazard exists (nothing hidden, nothing lost, no money/GL/chain bytes affected).

## Design sketch for the fix lane

- Distinct exit codes: 0 = all replays applied; 1 = one or more replays failed/dead-lettered (durable state written); 2 = usage/validation error (reserve `INVALID` for operator mistakes only). Follow the `VerifyEventChainCommand` remap precedent.
- Detect the non-retryable branch under `--sync` by re-reading the row's `projection_status` after `handle()` returns (Applied vs DeadLettered) instead of trusting the absence of a throw; count DeadLettered as failure in the tally.
- Update the scheduled-sweep wiring and any runbook that keys on exit codes in the same change.
- Covering tests: `--sync` replay of a row whose projector throws `NonRetryableProjectionException` → tally reports failure, exit 1, row DeadLettered; plus an exit-code matrix test.

A pointer comment exists at the `--sync` handle site in `RetryFiscalProjectionsCommand.php` (fix-round-1 follow-up commit).
