# Codex review — Offline-first shifts Phase 6.2 (background reconcile + banner)

**Date:** 2026-06-14
**Commit reviewed:** `3cd8ef112` (feat — background reconcile + advisory remote-close banner)
**Disposition commit:** `e84a8bf1c` (fix — address Codex r1)
**Verdict:** REQUEST-CHANGES → all findings resolved / accepted-with-rationale.

Codex was run via the codex-rescue subagent (output captured verbatim;
dispositioned here). Targeted scrutiny by the reviewer confirmed: transient-error
handling OK, no auto-close, cross-company/403 → unknown (no flip), verdict
distinctions correct given the OPEN/CLOSED server contract.

---

## Finding 1 — HIGH — audit failure can suppress the remote-close banner
`applyShiftReconcileVerdict` awaited `recordRemoteCloseConflict` BEFORE
`banner.flag`, and `runFullSync` catches reconcile errors non-fatally — so a
failed audit dedup/enqueue swallowed the conflict and the operator never saw the
advisory (the feature's primary safety behaviour).

**Disposition: ACCEPTED — fixed.** The banner is flagged FIRST; the audit is now
best-effort in its own `try/catch`. A failed enqueue logs, never hides the
banner, and leaves the dedup marker unset so the next tick retries. Test:
`still flags the banner even when the audit write fails`.

## Finding 2 — MEDIUM — audit idempotency only lasts until outbox pruning
Dedup queried `queued_audit_events`, which prunes synced rows after 14 days, so a
still-open conflict could re-enqueue a duplicate advisory after the prune /
across restarts — not durable once-per-shift.

**Disposition: ACCEPTED — fixed.** Dedup now keys on a durable `sync_metadata`
marker (`remote_close_audited:<shiftId>`), set only after a successful enqueue
(no marker-without-event gap). Exactly-once per shift, surviving prune + restart.
Dropped the now-unused `auditEventExistsForAggregate` outbox helper. Test:
`dedup survives outbox pruning`.

## Finding 3 — LOW/HYPOTHESIS — check-then-enqueue race if ticks overlap
Dedup is check-then-set with no DB unique constraint; overlapping `runFullSync`
runs could both enqueue.

**Disposition: ACCEPTED-WITH-RATIONALE — no change.** The `SyncScheduler` runs
ticks sequentially (not concurrently per terminal), and `setSyncMetadata` is an
idempotent upsert, so a duplicate advisory audit is bounded and harmless. Not
worth a transaction/unique-index for an advisory event. Noted here.

## Finding 4 — NIT — banner overflow on narrow POS screens
Single non-wrapping flex row with long localized subtitle.

**Disposition: ACCEPTED — fixed.** `RemoteShiftCloseBanner` now `flex-wrap` +
`text-center` so the copy wraps gracefully on narrow displays.

---

## Notes / confirmed-OK by the reviewer
- Transient/offline (non-404) → `unknown` → banner untouched (no flip on a blip). OK.
- No local auto-close anywhere in the reconcile path. OK.
- 403 (cross-company) → `unknown` (preserve state). OK.
- Banner clears on the next `healthy`/`none`/`not_projected` tick after a new
  shift opens (no imperative clear in `openShift` — by design; the reconcile is
  the single owner of the banner lifecycle).
