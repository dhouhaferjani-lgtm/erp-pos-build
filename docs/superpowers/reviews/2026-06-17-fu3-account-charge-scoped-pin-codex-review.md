# Codex review — FU-3 account-charge scoped manager-PIN migration

**Date:** 2026-06-17. **Branch:** `feat/fu3-account-charge-scoped-pin`.
**Reviewed commit:** `1aa3ad867`. **Fix commit:** `4500ff09c`.
**Reviewer:** Codex (gpt-5-codex via codex-companion).
**Verdict:** REQUEST-CHANGES (3 MEDIUM, 2 LOW) → all addressed.

Process note: Codex cannot write into this worktree sandbox; review recorded here with dispositions.

---

## Findings & dispositions

### MED1 — `targetReferenceIdRef` reused across attempts (dedup risk)
A once-per-mount `useRef` was reused as both the scoped verification's
`target_reference_id` and the captured override's `approvalId`. A second override
attempt in the same mount would reuse the id and collide with the fiscal append
dedup (`source_event_id`).
**Disposition: FIXED.** `verifyOverridePin` now mints a fresh UUID per attempt,
stored in the ref and used for both the audit anchor and the override approvalId.
Added a test asserting `override.approvalId === scoped call target_reference_id`.

### MED2 — fail-closed mapping collapsed service errors to "invalid PIN"
Every `verifyScopedManagerPin` throw became `{ valid: false }`, so a 503
service-unavailable / stale-cache failure counted as a failed PIN attempt and
throttled the manager.
**Disposition: FIXED.** A 5xx `ApiRequestError` is rethrown → `ManagerPinPanel`
shows "verification failed" WITHOUT consuming an attempt; only explicit auth
rejection (e.g. 403 wrong PIN) maps to `{ valid: false }`. Added a 503-surfaced-
without-capture test.

### MED3 — undefined `approvalContext` shown as "invalid PIN"
When context wasn't ready the adapter returned `{ valid: false }`, surfacing as a
failed PIN attempt + throttle.
**Disposition: FIXED.** The override panel is no longer rendered without a
context; a "manager override unavailable until the terminal is ready" message
shows instead. Added a missing-context test.

### LOW — ApprovalScope cast
No issue: `overridableScope` is constrained to `credit_limit_override |
account_status_override`, both valid server `ApprovalScope` values. Cast is
cosmetic; left as-is.

### LOW — stale test comment + coverage gaps
**Disposition: FIXED.** Corrected the integration-test boundary comment
(`scopedManagerPin`); added unit assertions for `target_event_type`, `reason`,
the `approvalId`/`target_reference_id` correlation, and the missing-context and
5xx paths.

---

## Verification after fixes
- `AccountChargeConfirmation.test.tsx` (7) + account-charge integration (1) +
  `Header.test.tsx` (7): 15 green.
- `tsc --noEmit`: 0 errors. `eslint` (changed files): 0 errors.

## Note (pre-existing, out of scope)
`DiscountModal` also collapses verify failures to "invalid PIN" (same throttle-on-
service-error pattern this fix corrects for account-charge). Codex confirmed FU-3
is not a regression vs that; consider applying the same 5xx-rethrow treatment to
DiscountModal in a future pass.
