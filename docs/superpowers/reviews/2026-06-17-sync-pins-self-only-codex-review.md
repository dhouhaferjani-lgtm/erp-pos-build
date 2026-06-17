# Codex review — sync-pins self-only (close FU-2b authorship residual)

**Date:** 2026-06-17. **Branch:** `feat/sync-pins-self-authored`.
**Reviewed commits:** `83b28c589` (client), `1a34ca3d7` (server). **Fix commit:** `7d35922b1`.
**Reviewer:** Codex (gpt-5-codex via codex-companion).
**Verdict:** self-only closes the targeted same-company rewrite; one MEDIUM in the retry path (now fixed).

Process note: Codex cannot write into this worktree sandbox, so the review was
emitted verbatim and is recorded here with dispositions.

---

## Findings & dispositions

### Q1 — self-only completeness — PASS (+ LOW)
`syncPins` rejects any target ≠ authenticated user before the write (self-only
gate). Codex confirmed the same-company authored rewrite is closed for this
endpoint. LOW: `PATCH /api/v1/users/{id}/pos-pin` (`UserController::setPosPin`)
is a deliberate non-self admin writer, gated on `users.update` + tenant scope —
NOT a `/pos/auth/sync-pins` bypass.
**Disposition: NO CHANGE.** `setPosPin` is intended admin user-management with
its own authz; out of scope for the offline-sync hardening.

### Q2 — server correctness — PASS
`(string)` UUID comparison sound; duplicate self-ids apply sequentially
(last-hash-wins, fine for self-only); the removed in-method company-member query
is covered upstream by the active-membership `CompanyContext` gate (FU-2a). 422
consistent with the endpoint's validation style (403 remains for `Gate` denial).
**Disposition: NO CHANGE.**

### Q3 — client correctness — PASS (+ LOW)
Per-author filter correct; null-auth returns 0 (no push) — correct fail-safe.
LOW: a row whose author never re-authenticates stays pending indefinitely —
acceptable vs. pushing under the wrong author; no data dropped.
**Disposition: NO CHANGE (accepted tradeoff); added a null-auth no-op test.**

### Q4 — scheduler / retry / row loss — MEDIUM
The PIN outbox stranded failed rows: `markPinUpdateFailed` sets `status='failed'`
but `getPendingPinUpdates` selected only `'pending'`, so a transient push failure
was never retried. Pre-existing, but with the new per-author drain this is the
only retry path for a queued PIN, so it had to be fixed.
**Disposition: FIXED (`7d35922b1`).** `getPendingPinUpdates` now selects
`status IN ('pending','failed') AND retry_count < MAX_PIN_UPDATE_RETRIES (5)`,
matching the fiscal-event / cash-drawer / audit outbox pattern (dead-letter past
the cap). Real-SQLite TDD tests for failed-under-cap re-selection + dead-letter.

### Q5 — test adequacy — LOW (gaps closed)
Added: null-auth no-op, API-failure marks only the current user's rows failed,
and the retry-path real-SQLite tests. (Duplicate same-user rows in one request
are covered by the existing idempotency test + last-hash-wins behavior.)

---

## Verification after fixes
- Server: `PosAuthSyncPinsTest` 8 tests green; `phpstan` 0 errors; `pint` pass.
- Client: `queuedPinUpdateRepository` (mock) + `.retry` (real SQLite) 7 green;
  `syncService` pushQueuedPinUpdates block 5 green; `tsc` 0; `eslint` 0.
  (Only the documented pre-existing `pullProducts > deleted_ids tombstone`
  remains red on dev — unrelated.)

## Residual / follow-up (owner-tracked)
- `UserController::setPosPin` is a non-self admin writer (intended; `users.update`
  + tenant-scoped). No change; note for completeness.
- A queued PIN whose author never re-authenticates stays pending (harmless).
