# Codex Adversarial Review — Offline-First POS Shifts Phase 4

**Date:** 2026-06-14
**Scope:** `git diff a027de9f0..d1424869f` (commit `d1424869f` — Phase 4: one-id consumer sweep + web-admin v3 UI gate)
**Reviewer:** Codex (gpt-5.x-codex), review-only
**Verdict:** REQUEST-CHANGES

> The Codex runtime could not write into the worktree sandbox; this file
> transcribes its returned findings plus the lead's disposition.

---

## HIGH-1 — Header X/Z fiscal authoring no longer gated to v3 (v2 regression)

The one-id sweep changed `Header.tsx` to pass `fiscalShiftIdForReceipt(shift)`
(which now always returns `shift.id`) for `fiscalShiftId`/`fiscalSessionId` on
**every** active shift. Downstream:

- `reportApi.generateXReport` appends an `X_REPORT` fiscal event whenever the
  fiscal opts are defined (no `requireFiscalEvents`/v3 check — `reportApi.ts`
  ~449-461 guard).
- `zReportService.generateZReport` → `buildFiscalCloseInput` (`zReportService.ts`
  ~530-538) returns non-null whenever the fiscal ids are defined, and the caller
  (~459-462) then appends `SESSION_CLOSE` + `Z_REPORT`.

For a v2 (server-authoritative) shift there is no local `SESSION_OPEN`, so this
flips X/Z into local fiscal authoring and `appendXReport`/`appendZSessionClose…`
either throw (`ZSessionLifecycleError`: append before SESSION_OPEN) or append a
close with no matching open. Pre-sweep this was implicit: v2 shifts had no
`shift.fiscal_shift_id`, so the opts were `undefined` and authoring was skipped.

**Recommended fix:** gate the Header fiscal-option construction on
`terminal.fiscal_schema_version === 3`, mirroring the `cashDrawerApi` v3 guard.

**Disposition: FIXED** (commit follows this review). Both `handleXReport` (xOpts)
and `handleEndOfDayConfirm` (fiscalZOpts) now only set `fiscalShiftId` /
`fiscalSessionId` when `terminal.fiscal_schema_version === 3`; v2 passes
`undefined` and stays on the server path. Clean-slate launch is all v3, so this
is a legacy-path correctness fix. Verified: tsc + Header/offline suites (126
tests) + eslint green.

## LOW-1 — cashDrawerApi test mocks `fiscalShiftIdForReceipt` inline

`apps/pos/src/api/__tests__/cashDrawerApi.test.ts` mocks the production helper
in the terminalStore module mock, so the test would not catch a broken import or
a future divergence in the real helper.

**Disposition: ACCEPTED as-is** — minor test-fidelity note, not a behavior
issue. The helper itself is covered directly by `terminalStore.test.ts`.

## No regressions found

- apps/web v3 UI gate (open + close hidden for v3; read-only views intact).
- cashDrawerApi v2/v3 branch behavior.
- The one-id collapse of `fiscalShiftId`/`fiscalSessionId` for v3 shifts
  (returned value identical for valid-UUID shifts).
