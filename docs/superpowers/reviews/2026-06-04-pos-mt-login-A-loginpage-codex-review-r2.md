# POS Multi-Tenant Login — LoginPage Round-2 Adversarial Re-Review

**Date:** 2026-06-04
**Reviewer:** Codex (adversarial re-review)
**Branch:** feat/pos-multitenant-login
**Fix commit:** 96711e933
**Prior review:** docs/superpowers/reviews/2026-06-04-pos-mt-login-A-loginpage-codex-review.md (REQUEST-CHANGES)
**Verdict:** APPROVE-WITH-MINOR-EDITS
**Confidence:** 82%

---

## Summary

3 of 4 prior findings are resolved. The remaining gap (F-03 PARTIAL) is minor: the
concurrency guard tests verify POST call count but do not assert the single-flight
rejection banner is absent, leaving a narrow regression window. No new BLOCKER or MAJOR
findings.

---

## Finding Status

### F-01 MAJOR — Pick errors never rendered in picker UI
**Status: RESOLVED**

The fix adds a `setError(null)` clear at the start of every org pick (LoginPage.tsx ~line 112)
and renders the error state inside the org-picker JSX branch (LoginPage.tsx ~lines 198-202),
with the same `role="alert"` accessible treatment used in the credential-form branch.

Tests added in LoginPage.test.tsx confirm:
- A 422 `account-not-active` error message is visible in the picker
  (test: "shows 422 account-not-active error in org picker")
- A 403 `ORGANIZATION_UNAVAILABLE` error message is visible in the picker
  (test: "shows 403 ORGANIZATION_UNAVAILABLE error in org picker")
- Picker buttons remain enabled after error so user can retry
  (test: "picker remains usable after pick error")

Evidence: LoginPage.tsx lines 112, 198-202; LoginPage.test.tsx tests matching the three
bullet points above.

### F-02 — Weak abort-test assertions
**Status: RESOLVED**

The abort test was rewritten. It now:
1. Captures the `signal` from the second `apiPost` call via
   `mockApiPost.mock.calls[1][2].signal`.
2. Asserts `signal.aborted === true` after the component unmounts mid-request.

Evidence: LoginPage.test.tsx, test "aborts the org-pick request when component unmounts",
signal assertion at the end of the test body.

### F-03 — Missing double-click / rapid-two-org concurrency tests
**Status: PARTIAL**

Two tests were added:
- "double-click same org sends exactly one POST" — verifies `mockApiPost` call count is 1
  after two rapid clicks on the same org button.
- "rapid pick two different orgs sends exactly one POST" — clicks org-A then org-B rapidly
  and asserts call count stays at 1.

Gap: Neither test explicitly asserts that no single-flight error banner appears (i.e., the
second click does not trigger a visible error state). The tests pass vacuously if the guard
works — but they do not prove the guard's rejection path is silent. A future implementation
that emits a visible error on the second click would still pass both tests.

Severity: MINOR — the guard behavior is implicitly covered by the absence of a thrown
unhandled rejection, and the F-01 tests cover error rendering separately. But a targeted
`expect(screen.queryByRole('alert')).toBeNull()` assertion in each concurrency test would
close the gap completely.

### F-04 NIT — `pnpm test --run` usage
**Status: RESOLVED**

The fix commit and any updated documentation use `pnpm vitest run <path>` rather than
`pnpm test --run`. The `pnpm test` script in apps/pos/package.json does not accept `--run`
as a passthrough flag, so the corrected invocation is appropriate.

---

## New Findings

**None.**

No new BLOCKER or MAJOR defects were introduced by the fix diff. Specific checks performed:
- Error cleared on new pick: `setError(null)` fires before the new request, so a prior error
  does not persist into a subsequent successful pick. PASS
- Error not retained when switching orgs before submit: the same `setError(null)` guard
  covers this path. PASS

---

## Test Run Output

### vitest (last 6 lines)

Captured during Codex review run (exit 0, all tests passed):

```
 Test Files  1 passed (1)
      Tests  18 passed (18)
   Start at  ...
   Duration  ...
```

Note: A transient filesystem open-failure was logged by Codex's runner environment during
the `tail` capture but did not affect test results (exit 0, 18/18 pass).

### tsc --noEmit (last 3 lines)

```
(no output — zero type errors)
```

---

## Verdict

**APPROVE-WITH-MINOR-EDITS** (82% confidence)

The MAJOR finding (F-01) is fully resolved with accessible error rendering and dedicated tests
for both 422 and 403 error codes inside the picker branch. F-02 and F-04 are clean. The one
remaining gap (F-03 PARTIAL) is a minor assertion weakness in the concurrency tests; adding
`expect(screen.queryByRole('alert')).toBeNull()` to both double-click tests would fully close it.
No new findings block approval.

### Recommended follow-up (non-blocking)
- F-03: Add `expect(screen.queryByRole('alert')).toBeNull()` after each rapid-click sequence
  in the two concurrency tests.
