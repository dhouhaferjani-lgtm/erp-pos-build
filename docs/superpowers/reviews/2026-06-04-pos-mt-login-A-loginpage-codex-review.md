# POS Multitenant LoginPage Review

- Date: 2026-06-04
- Reviewer: Codex adversarial
- Commit range: `dcbd12bf0..4014f6952`
- Files reviewed: `apps/pos/src/pages/LoginPage.tsx`, `apps/pos/src/pages/__tests__/LoginPage.test.tsx`, `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`
- Supplemental context inspected: `apps/pos/src/stores/authStore.ts`, `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md`, `docs/superpowers/plans/2026-06-04-pos-multitenant-login-A.md`, `apps/pos/package.json`

## Findings

### F-01

- Severity: MAJOR
- Category: ERROR SURFACING
- Classification: confirmed defect
- Problem statement: Non-abort errors from manual organization selection are stored in `error` but never rendered while the organization picker is visible. This makes wrong-password-after-picker, `422 account_not_active`, `403 ORGANIZATION_UNAVAILABLE`, and `UnexpectedLoginResponseError` visually silent on the exact UI branch where they occur. The spec requires inactive and suspended/archived responses to surface distinctly.
- Evidence:
  - `apps/pos/src/pages/LoginPage.tsx:118-119` catches non-abort pick errors and calls `setError(getErrorMessage(err))`.
  - `apps/pos/src/pages/LoginPage.tsx:130-163` returns the org-picker UI, but that branch only renders the title, org buttons, and optional cancel button. It does not render `error`.
  - The only error rendering is in the login form branch at `apps/pos/src/pages/LoginPage.tsx:247-249`, which is unreachable while `organizations` is non-null because the org-picker branch returns earlier.
  - Spec edge cases require these messages to surface: wrong password at `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md:187-190`, inactive user at `:191`, and suspended/archived org at `:192-193`.
- Recommended fix: Render the existing `error` state inside the org-picker branch, near the picker heading or below the org list, using the same accessible error treatment as the form branch. Add component tests where the second tenant-bound `apiPost` rejects with a 422 account-not-active message and a 403 organization-unavailable message, then assert the message is visible while the picker remains available for another selection.

### F-02

- Severity: MINOR
- Category: TEST INTEGRITY
- Classification: risk
- Problem statement: The manual-pick abort test can pass without proving the cancel button actually aborted the pick request or settled the in-flight login. In the current run it did exercise the abort path, as shown by the `DOMException` stderr, but the assertions do not lock that behavior in.
- Evidence:
  - `apps/pos/src/pages/__tests__/LoginPage.test.tsx:299-303` creates a second `apiPost` promise that only rejects when the passed signal aborts.
  - `apps/pos/src/pages/__tests__/LoginPage.test.tsx:324-328` clicks `org-pick-cancel`, then asserts only `isAuthenticated === false` and `token === null`. Those are also the initial state before clicking cancel.
  - The test does not capture the second call's `AbortSignal`, assert `signal.aborted`, assert the promise rejected, or assert the pending/cancel UI settled after abort.
- Recommended fix: Capture the second `apiPost` `AbortSignal` and a rejection/settlement flag. After clicking cancel, flush microtasks and assert `signal.aborted === true`, the mocked request rejected with `AbortError`, and the cancel affordance disappears or the org buttons are re-enabled.

### F-03

- Severity: MINOR
- Category: TEST INTEGRITY
- Classification: risk
- Problem statement: Required picker concurrency cases are not covered at the `LoginPage` level. Static review did not prove a live double-click race in normal React event ordering because `authStore.login()` sets `isLoading` synchronously and rejects concurrent calls, but the spec explicitly calls for same-org double-click and rapid two-org tests.
- Evidence:
  - The spec requires double-click and rapid two-org selection to produce one tenant-bound POST and one committed auth state at `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md:147-152`.
  - The `LoginPage` business-picker test block contains only render, selected-tenant POST body, and abort tests at `apps/pos/src/pages/__tests__/LoginPage.test.tsx:252-329`; there is no double-click or rapid two-org assertion.
- Recommended fix: Add two component tests: one double-clicking the same org and one clicking two different org buttons before the first request settles. Assert exactly one tenant-bound `apiPost`, all org buttons disabled while pending, no second error banner from the store single-flight guard, and one final authenticated state.

### F-04

- Severity: NIT
- Category: TEST INTEGRITY
- Classification: confirmed verification issue
- Problem statement: The exact test command requested in the review prompt does not run in this package because `pnpm test` already maps to `vitest run`; appending `--run` passes an invalid flag to Vitest through the script.
- Evidence:
  - `apps/pos/package.json:13` defines `"test": "vitest run"`.
  - Command run from `apps/pos`: `pnpm test --run 2>&1 | tail -80`.
  - Output: `ERROR Unknown option: 'run'`.
- Recommended fix: Use `pnpm test` for the full suite, or `pnpm vitest run ...` for targeted files. Do not append `--run` to the `test` script.

## Attack Vector Coverage

- RACE/CONCURRENCY: No confirmed runtime double-click defect found from code inspection; store single-flight guard is present at `apps/pos/src/stores/authStore.ts:188-193`, but component-level double-click and rapid two-org tests are missing (F-03).
- ABORT CORRECTNESS: `abortControllerRef` and `pickAbortRef` are separate refs (`LoginPage.tsx:34`, `:38`), pick abort resets pending in `finally` (`:120-122`), and the focused tests exercise the current abort path. Test assertion strength is weak (F-02).
- STALE CLOSURES: No confirmed defect observed. `handleOrgSelect` reuses the email/password captured by the rendered picker (`LoginPage.tsx:107-110`), which matches the selected email-first login attempt because the form is no longer rendered while the picker is active.
- UI STATE MACHINE: No confirmed authenticated-picker overlap observed. The authenticated pick path clears organizations before company selection (`LoginPage.tsx:111-116`). Failed picks keep the picker visible, which is desirable, but their error is hidden (F-01).
- ERROR SURFACING: Confirmed defect for manual org-pick errors (F-01).
- TEST INTEGRITY: Abort assertion weakness and missing concurrency tests found (F-02, F-03). The `beforeAll`/`beforeEach` real-login restore pattern is acceptable within this file because `originalLoginAction` is captured before test mutation at `LoginPage.test.tsx:109-112` and restored for picker tests at `:245-249`.
- REGRESSION CHECK: Focused auth/login tests pass. Full suite has unrelated migration failures listed below.

## Verification

- Requested command: `pnpm test --run 2>&1 | tail -80` from `apps/pos` failed with `ERROR Unknown option: 'run'`.
- Targeted page command: `pnpm vitest run src/pages/__tests__/LoginPage.test.tsx 2>&1 | tail -80` passed: 1 file, 7 tests.
- Focused auth/login command: `pnpm vitest run src/stores/__tests__/authStore.test.ts src/pages/__tests__/LoginPage.test.tsx 2>&1 | tail -80` passed: 2 files, 40 tests.
- Actual full suite command: `pnpm test 2>&1 | tail -80` ran but failed outside this login work: `src/lib/db/__tests__/migrations.v37.test.ts` has 2 failing tests (`fiscal_event_genesis_seed` expected empty string but got `seed`, and missing canonical chain unique index).

## Summary Table

| Severity | Count |
|---|---:|
| BLOCKER | 0 |
| MAJOR | 1 |
| MINOR | 2 |
| NIT | 1 |

Final verdict: REQUEST-CHANGES, confidence high.
