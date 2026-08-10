# Applied Projections With a Skipped Tolerance GL Leg Have No Re-Post Path

Raised by: treasury-reviewer gate verdict (item I, 2026-08-10), finding 1.
Status: design needed. Pre-existing; NOT introduced by the C/G/H/I lane.

## The gap

`TreasuryReceiptBridge` skips the rounding / tolerance GL entry when the required system-purpose account is missing (e.g. a TN company with no `PaymentToleranceExpense` / 6580), records a `pos.gl.tolerance_purpose_missing` alert, and lets the projection **commit as `Applied`**. Fix round 1 made that alert durable — a failure to persist it now rolls the projection back for retry — but it did nothing for the rows that already committed `Applied` with the leg skipped, because **no tooling can reach them**:

- `RetryFiscalProjectionsCommand::candidateRows()` selects only `DeadLettered` OR `Pending` with `attempts >= 5`. An `Applied` row is never a candidate, and `isRetryable()` rejects it even if targeted by id.
- `EnqueueResolvedEventProjectionsCommand` inserts with `ON CONFLICT DO NOTHING` and only dispatches `pending` rows, so it will not recreate or re-drive an applied one.
- `ApplyFiscalEventProjectionJob`'s T_lock short-circuits on terminal states, so even a hand-dispatched job returns without work.

Scenario: a TN company is provisioned without 6580. Every rounded cash receipt under-books the rounding leg. Seeding 6580 later fixes all FUTURE receipts and recovers NONE of the historical ones. The GL is quietly short by the sum of the skipped legs, and the only evidence is a pile of `audit_events` rows.

This also undercuts the country-defaults spec's classification of the tolerance purposes as warning-only: "warning" is defensible only if a remediation path exists.

## What a fix has to decide

- A targeted re-post path for these source types — e.g. `fiscal:retry-projections --include-applied --projector=treasury_receipt_bridge --source-type=pos_tolerance_bridge`, or a purpose-built `treasury:repost-skipped-tolerance-legs` command driven off the `pos.gl.tolerance_purpose_missing` alerts rather than off projection status.
- Idempotency: the bridge's existing `journalEntryExists(source_type, source_id)` probe already makes a re-post safe for the MISSING entry while leaving the already-posted receipt/rounding entries alone. Confirm that holds for every combination of skipped/posted legs before relying on it.
- Whether re-posting into a CLOSED accounting period is permitted, and if not, what the operator does instead (adjusting entry in the open period, with its own justifying document per the document-per-action principle).
- Whether `--include-applied` is too sharp a tool to exist generally; scoping it to named source types is probably safer than a global flag.

## Dead-letter blast radius (verdict finding 3, recorded here)

The fail-closed change shipped in this lane has an accepted trade-off worth stating explicitly. When the alert write fails **permanently**, the treasury projection — the `payments` row, the POS-revenue GL entry, and the cash movement — is withheld and the row dead-letters after roughly 21 minutes of Horizon backoff. Meanwhile `PosCoreReceiptProjection` has already committed `pos_receipts` independently: the receipt is printable and visible while the money leg is absent, a cross-projection divergence.

This is the correct trade (never acknowledge a projection whose only durable operator signal was lost), the window is bounded, and recovery is a documented one-liner:

```
php artisan fiscal:retry-projections --projector=treasury_receipt_bridge --tenant=<id> --sync
```

That path now works correctly even when the replay fails again (fix round 1, C1). The residual risk is operational visibility, not correctness.
