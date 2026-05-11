# PR #116 — Per-phase AbortController for bootstrap skip-with-cache + reset — Codex review trail

**Branch:** `feat/pos-bootstrap-abort-on-skip`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 1 round, APPROVE.

This PR adds per-phase abort ownership to the bootstrap state machine so abandoned bootstrap work can be cancelled when the cashier uses cached data or logs out/resets during a phase.

## Round 1 — APPROVE

> The abort controller integration is consistently threaded through the bootstrap phases and the touched store methods, and the targeted tests plus typecheck pass. I did not find a discrete regression introduced by this diff that would warrant an inline finding.

No findings. PR ready for merge.

## Final shape

- **2 commits**.
- **7 files changed**:
  - `apps/pos/src/stores/bootstrapStore.ts`
  - `apps/pos/src/stores/authStore.ts`
  - `apps/pos/src/stores/terminalStore.ts`
  - `apps/pos/src/stores/operatorStore.ts`
  - `apps/pos/src/stores/__tests__/bootstrapStore.test.ts`
  - `apps/pos/src/lib/bootstrap/__tests__/withTimeout.test.ts`
  - `docs/superpowers/reviews/2026-05-11-pos-bootstrap-abort-on-skip-codex-rounds.md`
- POS gates:
  - targeted abort tests — 41/41 pass.
  - `pnpm typecheck` — 0 errors.
  - `pnpm lint` — 0 errors / 41 warnings.
  - `pnpm test` — 1210/1210 pass across 133 files.

## Pre-flight audit

- **L1 cross-tenant audit:** Menu, standard-retail, hybrid, and non-Menu tenants all share the same auth/company/terminal/PIN bootstrap path. No tenant-specific cart, product, receipt, sync, or menu shape changes.
- **L8 ownership audit:** `BootstrapErrorScreen` continues to own cashier recovery controls; `bootstrapStore` now owns per-phase abort lifecycle. Domain stores keep their state ownership and only accept optional cancellation signals.
- **L9 ingress audit:** audited bootstrap phase entry, `skipWithCache()`, `reset()`, `start()`/`retry()`, optional signal threading into store methods, API signal support, and timeout-helper behavior after timeout.
- **Reset/logout path:** `reset()` increments the run generation and aborts the current phase, preventing late bootstrap error writes after logout/reset.
- **Skip-with-cache path:** timed-out recoverable phases keep their controller until `skipWithCache()` aborts the abandoned underlying work before continuing from the next phase.
