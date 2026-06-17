# Codex Adversarial Review — Offline-First POS Shifts Phase 5 B7

**Date:** 2026-06-14
**Scope:** `git diff 940282943..b359f719a` (commit `b359f719a` — offline manager-PIN approval for the EOD above-hard-variance close, via REUSE of operator_pins/operatorApproval rather than a distinct manager_pins table)
**Reviewer:** Codex (gpt-5.x-codex), review-only
**Verdict:** REQUEST-CHANGES

> Codex runtime could not write into the worktree sandbox; this transcribes its
> findings + the lead's disposition.

Confirmed-correct: the `targetOperatorId` pin-to-selected-manager; the
`{valid:false}` vs rethrow error mapping (local no-match / explicit 4xx denial →
countable attempt; 503/unexpected → rethrow, no downgrade); the online payload
shape accepted by the server for `close_shift_variance` / `SESSION_CLOSE`;
`managerPinApi.verifyManagerPin` still used by account-charge flows (not dead).

---

## BLOCKER — `pullOperatorPins` omitted `terminal_id` → every mirrored operator had `terminal_ids: []`

`pullOperatorPins` called `/pos/auth/pin-data` with no `terminal_id`;
`PosAuthController` sets `terminal_ids = [terminal_id]` only when the param is
present, else `[]` (PosAuthController.php:144-145). `verifyOfflineApprovalPin`
requires `operator.terminal_ids.includes(terminalId)` (approvalVerifier.ts:29-33),
and `verifyScopedManagerPin` runs that local match BEFORE any online call — so
every manager (and every override-flow operator) resolved to
`manager_pin_scope_mismatch`. Header maps that to `{valid:false}` → a valid
manager PIN counts as a bad PIN and throttles the cashier, and offline approval
is impossible. This was **pre-existing** for the whole offline operator-approval
path (cash-drawer / discount overrides), surfaced because B7 depends on it.

**Disposition: FIXED.** `pullOperatorPins(db, terminalId)` now requests
`/pos/auth/pin-data?terminal_id=<id>`; both callers updated (the F-8 eager
activation pull in `seedOfflineHashChain`, and the `runFullSync` sync-cycle pull).
+test asserting the scoped URL. This also fixes the pre-existing offline-override
brokenness.

## HIGH — F-2: v3 close could still fail OPEN when online-but-fraud-unavailable

The fail-closed guard was `fraudSettings === null && !isOnline`, so an
online-but-fraud-fetch-failed close with an empty cache left `fraudSettings`
null and degraded to a preview-only close without the variance gate.

**Disposition: FIXED.** The guard is now `fiscal_schema_version === 3 &&
fraudSettings === null` (drops `!isOnline`). `fraudSettings` is only null when
the policy genuinely failed to load (the server always returns one), so this
blocks every unguarded v3 close without false-blocking a normal one.

## HIGH — F-3: offline manager list / verifier don't enforce ACTIVE company membership

`/pos/auth/pin-data` filters company-membership *existence* + derives scopes from
global permissions; `PinVerifier::verifyForApproval` has the same gap. The online
`AuthorizedManagersController` filters *active* memberships. So a user with a
suspended/revoked membership but a retained `pos_pin` + `pos.close_shift_with_variance`
permission can appear in the offline manager list and locally approve a close.

**Disposition: FOLLOW-UP (filed), NOT fixed here.** This is a PRE-EXISTING gap in
the shared operator-approval infra that B7 reuses — it affects *all* offline
override flows equally and the fix is server-side (align `pin-data` query +
`PinVerifier` with `AuthorizedManagersController`'s active-membership filter +
regression tests). It is out of B7's scope (B7 introduced no new auth surface),
and clean-slate launch has no suspended managers. **Recommend a dedicated
follow-up: enforce active `UserCompanyMembership` in `pin-data` + `PinVerifier`.**

## Checklist verdicts (from Codex)
1. Error mapping — PASS. 2. Anti-downgrade integrity — PASS once the BLOCKER is
fixed (terminal_ids populated). 3. F-1 fail-closed — now complete (F-2 fix).
4. Offline managers list — membership-status gap → F-3 follow-up. 5. Online
payload validation — PASS. 6. Removed Header import / dead code — PASS.
