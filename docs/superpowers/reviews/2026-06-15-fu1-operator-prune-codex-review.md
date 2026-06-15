# Codex review — FU-1 operator-PIN cache prune

**Date:** 2026-06-15. **Branch:** `feat/offline-approval-operator-prune`.
**Reviewed commit:** `76bd01923`. **Fixes commit:** `52ed024e4`.
**Reviewer:** Codex (gpt-5-codex via codex-companion).

Process note: the Codex runtime cannot write into this worktree sandbox, so the
review was emitted verbatim and is recorded here by Claude, with dispositions.

---

## Findings & dispositions

### 1. Correctness of placeholder binding — PASS (+ LOW)
Codex: positional `$1..$n` for `keepIds` then `$n+1` for the active id bind
correctly. LOW: `pruneOperatorsExcept(db, [], null)` would run a bare
`DELETE FROM operator_pins` (no WHERE) and wipe the cache; only the call-site
`operators.length > 0` gate prevents it.

**Disposition: FIXED.** Empty `keepIds` is now an explicit defensive no-op
(`return 0`). Added a repo test asserting an empty keep-list deletes nothing.

### 2. Safety boundary (partial/attacker-controlled pulls) — PASS (+ LOW)
Codex: no client-controlled way to get a partial-but-non-empty `/pin-data`
response — the controller validates only an optional `terminal_id` (used to
stamp `terminal_ids`, not to filter the operator set) and selects from active
company memberships with a non-null `pos_pin`. So the non-empty gate is a sound
boundary. LOW: the prune is not tenant-scoped though rows carry `tenant_id`.

**Disposition: NO CHANGE (LOW dismissed with reasoning).** The local DB is
keyed per company (`izipos-<companyId>.db`, `apps/pos/src/lib/db.ts`); switching
companies opens a different file, and pin-data is company+tenant scoped
(`company_ids => [$company->id]`). One DB therefore holds exactly one
company/tenant, so pruning by `id` cannot cross-wipe another tenant. Consistent
with `getAllOperators`, which also has no tenant filter.

### 3. Cache invalidation completeness — PASS
Codex: deleting the `operator_pins` row removes the operator from future offline
manager approval (the approval path re-reads SQLite per verification). The
Zustand operator-session state is a separate concern, not a manager-approval
cache.

**Disposition: NO CHANGE.** Confirmed.

### 4. Active-operator exception abuse — HIGH
Codex: `id != activeOperatorId` can retain a suspended operator if that operator
is the authenticated active user when sync runs (suspended members are excluded
from the server response, but the client then explicitly exempts the active id
from deletion).

**Disposition: FIXED.** Verified against `PosAuthController::pinData`: the server
returns every ACTIVE member with a `pos_pin` and does not filter by terminal, so
a legitimate active operator is always in `keepIds`. The carve-out could only
ever fire when the active operator was omitted (= just suspended), shielding
them. Removed the carve-out entirely; `pruneOperatorsExcept(db, keepIds)` now
prunes strictly by keep-set, and the `activeOperatorId` threading through
`runFullSync` / `terminalStore` was reverted. No lockout risk (legit active
operators are always returned).

### 5. Test adequacy — MEDIUM
Codex: repo tests cover real-SQLite delete/keep/empty; sync tests mock
`pruneOperatorsExcept` so don't prove end-to-end deletion. Missing: multi-keepIds
binding with deletion, e2e deletion via `pullOperatorPins`, suspended-active
retention case.

**Disposition: FIXED.** Added `syncService.operatorPrune.integration.test.ts`
(real SQLite, real repo, only `apiGet` mocked) proving a suspended-then-omitted
operator is genuinely deleted and that an empty pull deletes nothing. Added a
multi-keepIds-with-deletion repo test. The suspended-active retention case is
moot now the carve-out is gone (covered by the e2e prune test).

### Approval-scope TTL — "should be required, not optional"
Codex: `verifyOfflineApprovalPin` never checks `approval_scope_permissions_
fetched_at`; a fail-closed TTL would bound stale approval authority for a device
that goes offline and never gets a successful pull (the one window pruning can't
close).

**Disposition: DEFERRED to owner decision (FU-1b).** This is a genuine
defense-in-depth gap but a distinct change: it touches the verification path and
needs a product/security decision on the TTL threshold. The followup doc framed
the TTL as an *alternative* to pruning; Codex elevates it to a complement. Not
folded into this PR to keep FU-1 scoped to the authoritative-prune fix. Tracked
for owner sign-off (threshold value + whether to ship in this PR vs. a follow-up).

---

## Verification after fixes
- `operatorPinRepository.prune.test.ts` + `syncService.operatorPrune.integration.test.ts`: green.
- `syncService.test.ts` pullOperatorPins block: green (only the pre-existing,
  documented `pullProducts > deleted_ids tombstone` failure remains red on `dev`).
- `terminalStore.preWarm.test.ts`: green.
- `tsc --noEmit`: 0 errors. `eslint` on changed files: 0 errors.
