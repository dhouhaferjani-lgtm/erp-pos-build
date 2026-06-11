# M5 — Off-device fiscal durability (Phase 2 deferral)

> Launch-blocker audit (2026-06-09) item **M5**, MEDIUM. Documented deferral with the
> mitigations that make it acceptable for launch + the Phase-2 scope.

## The gap

Device-authored fiscal events (SALE_RECEIPT, Z_REPORT, …) are written to local SQLite and
hash-chained on the device, but the **only durable, off-device copy is the server sync**. If a
terminal's disk is lost between authoring an event and syncing it, those events are gone — the
on-device hash chain cannot be recovered from anywhere else. The "off-device durability" layer
(e.g. a second local sink, append-only WAL shipping, or a peer/backup target) is a Phase-2 stub.

## Why this is acceptable for launch (mitigations IN PLACE)

1. **Tight sync interval** — `src/lib/sync/syncScheduler.ts` runs the sync loop on a short
   interval and resets it on activity, so the unsynced window is small in normal operation.
2. **Unsynced risk indicator** — `src/components/fiscal/UnsyncedRiskIndicator.tsx` surfaces the
   count/age of unsynced fiscal events to the operator.
3. **Durability gate at close** — `src/components/fiscal/DurabilityGateModal.tsx` gates the
   shift close on the unsynced backlog so a shift is not closed with a large un-synced tail.
4. **Server is the source of truth** — once synced, the server holds the authoritative chain;
   per-terminal disk loss after sync is fully recoverable.

Residual risk window = events authored since the last successful sync, bounded by the interval
and surfaced by the indicator. The owner accepts this for launch.

## Phase 2 scope (post-launch)

- A second durable local sink (append-only event log shipped independently of the projection DB),
  or OS-level continuous backup of the SQLite event store.
- Backlog-drain measurement under real Wi-Fi conditions (already on the deploy-phase-2 list).
- A documented disk-loss recovery procedure (what the server can rebuild + the unrecoverable
  window) tied to the verify-all-chains runbook.

## Status

Deferred to Phase 2 by owner decision. Mitigations verified present 2026-06-11. No launch-blocking
code change required; this ticket tracks the residual risk and the Phase-2 work.
