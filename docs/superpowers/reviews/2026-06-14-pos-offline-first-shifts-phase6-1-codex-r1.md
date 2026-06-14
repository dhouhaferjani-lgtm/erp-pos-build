# Codex review — Offline-first shifts Phase 6.1 (counter seed)

**Date:** 2026-06-14
**Commit reviewed:** `ace19eaae` (feat — seed device shift_number counter from server MAX)
**Disposition commit:** `d43e44d2e` (fix — address Codex r1)
**Verdict:** REQUEST-CHANGES → all findings resolved.

Codex was run via the codex-rescue subagent (it cannot write into this worktree
sandbox, so it output the review verbatim; captured + dispositioned here).

---

## Finding 1 — HIGH — non-atomic seed persistence
`pullTerminalState` wrote `terminal_state` (`upsertTerminalState`) then the
`shift_number_seed` (`setShiftNumberSeed`) as two separate statements. A crash
between them leaves a fresh install with a valid `terminal_state` row but seed
`0`; if the device then opens a shift offline before a successful retry,
numbering restarts at 1 — the exact silent collision Phase 6.1 prevents.

**Disposition: ACCEPTED — fixed.** The single-writer rule forbids a `BEGIN` on
the pooled connection, so the seed is folded INTO `upsertTerminalState`'s single
`INSERT … ON CONFLICT` statement with a monotone guard
(`shift_number_seed = MAX(terminal_state.shift_number_seed, excluded.shift_number_seed)`).
The fresh-install path is now atomic (one write). The FiscalRegression-preserved
path (where the upsert rejects the whole write) keeps its own
`setShiftNumberSeed` refresh — the row pre-exists there, so no fresh-install
collision risk. Tests: atomic + monotone + default-0 against real SQLite (3);
`pullTerminalState` success asserts the seed rides the upsert, regression path
asserts the separate refresh.

## Finding 2 — MEDIUM — `(terminal_id, shift_number)` only indexed, not UNIQUE
The device `local_shifts` had a plain lookup index; the server already enforces
`pos_shifts (terminal_id, shift_number)` unique
(`2026_06_14_110000_make_pos_shifts_terminal_shift_number_unique`). No DB-level
backstop against a regressed caller / import / restore reusing a number.

**Disposition: ACCEPTED — fixed.** Migration **v55** promotes the v53 plain
index to UNIQUE (`idx_local_shifts_terminal_number_unique`), mirroring the
server and the spec §4(d) intent (NF525 "sans rupture de séquence"). Collision
is impossible on the happy path (numbers minted monotonically in-tx; the seed
only raises the floor), so this is a fail-loud backstop. Clean-slate/pre-live →
no remediation needed. Tests: duplicate-same-terminal rejected, same-number
cross-terminal allowed, old plain index dropped, re-runnable (4).

## Finding 3 — LOW — N+1 on the terminal collection endpoints
`TerminalResource` always emits `max_shift_number`, but `index()` / `available()`
did not `withMax`, so a collection render fired a lazy per-row
`shifts()->max(...)`.

**Disposition: ACCEPTED — fixed.** `index()` and `available()` now
`->withMax('shifts', 'shift_number')` (the `show()` path already did). Lazy
fallback in the resource is retained for any caller that doesn't eager-load.

---

## Notes carried forward
- The reviewer flagged that `setShiftNumberSeed`'s tolerance was unverified
  out-of-diff. It is monotone-only and assumes the v54 column exists (all sync
  paths run migrations first); the tolerant read lives in `getShiftNumberSeed`.
- No other consumer of `TerminalResource.max_shift_number` exists.
