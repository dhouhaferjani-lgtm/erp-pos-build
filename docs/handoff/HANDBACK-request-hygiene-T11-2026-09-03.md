# HANDBACK — Request Hygiene Phase A, Task 11 (shared idempotency-key hook)

- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 11: Shared idempotency-key hook (ID-1..ID-4 foundation)`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t11`
- **Branch:** `lane/rh-t11-idempotency-hook` (based on `dev`, base commit `6f16fd8f7`)
- **Commits:** `d7cc53d53` (initial hook + test + handback) → `a5b4cc443` (hash fill-in) → **`c2c9cf2c7` (gate r1 CHANGES applied — relocation + test additions)**. A doc-only follow-up commits this updated handback, since a commit cannot contain its own hash.
- **Final paths:** `apps/web/src/hooks/useIdempotencyKey.ts` and `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx`. **Consumers must import `@/hooks/useIdempotencyKey`** (not `@/lib/hooks/...`).
- **Result:** all checks passed, at the original round and again after the gate r1 changes. See the **Gate r1** section at the bottom for the post-change command output.

## Files

- Create `apps/web/src/hooks/useIdempotencyKey.ts` (originally `src/lib/hooks/`, relocated in gate r1)
- Create `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx` (originally `src/lib/hooks/`, relocated in gate r1)

Nothing else was touched. Task 11's gate-r2/r3 verdict is "Yes — isolated hook with no pre-existing consumer"; this lane adds no consumer (that is Task 12).

## Step 1 — red test

Command:

```
cd apps/web && pnpm vitest run src/lib/hooks/useIdempotencyKey.test.tsx
```

Tail of output (implementation file absent):

```
⎯⎯⎯⎯⎯⎯ Failed Suites 1 ⎯⎯⎯⎯⎯⎯⎯

 FAIL  src/lib/hooks/useIdempotencyKey.test.tsx [ src/lib/hooks/useIdempotencyKey.test.tsx ]
Error: Failed to resolve import "./useIdempotencyKey" from "src/lib/hooks/useIdempotencyKey.test.tsx". Does the file exist?
  Plugin: vite:import-analysis
  File: .../src/lib/hooks/useIdempotencyKey.test.tsx:4:34
  2  |  import { act, renderHook } from "@testing-library/react";
  3  |  import { describe, expect, it } from "vitest";
  4  |  import { useIdempotencyKey } from "./useIdempotencyKey";
     |                                     ^

 Test Files  1 failed (1)
      Tests  no tests
   Duration  1.27s
```

This is exactly the failure mode Step 1 names ("module-not-found red test") — the suite fails to collect, not an assertion failure.

## Step 2 — implementation

`apps/web/src/lib/hooks/useIdempotencyKey.ts` is the plan's body verbatim (`useState(() => crypto.randomUUID())` + `useCallback` reset), plus a doc comment recording the plan's semantic note: the key survives failed submits and only a consumer that awaited a success calls `reset()`.

## Step 3 — green run

Command:

```
cd apps/web && pnpm vitest run src/lib/hooks/useIdempotencyKey.test.tsx
```

Tail:

```
 RUN  v3.2.4 .../apps/web

 ✓ src/lib/hooks/useIdempotencyKey.test.tsx (1 test) 9ms

 Test Files  1 passed (1)
      Tests  1 passed (1)
   Duration  1.29s (transform 191ms, setup 409ms, collect 95ms, tests 9ms, environment 373ms, prepare 63ms)
```

## Typecheck

```
cd apps/web && pnpm typecheck        # tsc --noEmit
TYPECHECK_EXIT=0
```

No diagnostics emitted (whole-project `tsc --noEmit`, not just the lane files).

## Lint

```
cd apps/web && pnpm eslint src/lib/hooks/useIdempotencyKey.ts src/lib/hooks/useIdempotencyKey.test.tsx
ESLINT_EXIT=0
```

No output = zero errors and zero warnings on both files.

## Deviations from the plan

1. **The test body is wrapped in a `describe('useIdempotencyKey', ...)` block.** The plan's snippet imports `describe` from vitest but never uses it; shipped verbatim it would be an unused import. `apps/web/eslint.config.js:188` demotes `@typescript-eslint/no-unused-vars` to `warn` for most paths, so it would not have failed the lint gate, but a warning-free file was preferable to dropping an import the plan asked for. The `it(...)` body, its four assertions and the `act(() => { reset() })` call are unchanged.
2. **Import formatting.** A blank line separates the third-party imports from the local `./useIdempotencyKey` import, matching the house style in the sibling `apps/web/src/lib/formErrors.rhf.test.tsx`. No functional difference.
3. **Commit message.** Task 11 has no commit message in the plan text (a repo-wide grep for "commit" in the plan returns only the path-scoping rule at line 22 and unrelated prose). I used a conventional-commits message in the style of the surrounding history: `feat(web): shared useIdempotencyKey hook (request hygiene T11)`.

No harness adaptation was needed otherwise: `apps/web/vitest.config.ts` sets `globals: true`, `environment: 'jsdom'`, `setupFiles: ['./src/test/setup.ts']` and `include: ['src/**/*.{test,spec}.{ts,tsx}', ...]`, so the new path is picked up with no config change, and `renderHook`/`act` from `@testing-library/react` are the harness the sibling `src/lib` tests already use.

## For the reviewer

- **`crypto.randomUUID()` availability.** It resolves against the jsdom/Node global in tests (green run proves it) and against the browser global at runtime. `crypto` is used unguarded, matching the plan; the app targets secure contexts only (`crypto.randomUUID` is unavailable on plain-HTTP non-localhost origins). If any deployment surface is served over plain HTTP, this hook will throw there — worth confirming against the staging/production origin scheme before Task 12 wires it into payment surfaces.
- ~~**`src/lib/hooks/` is a new directory.**~~ **RESOLVED in gate r1** — the reviewer ruled for `src/hooks/`, and the flagged risk turned out to be real (see Gate r1 below). This is a deliberate, recorded deviation from the plan's stated path; the plan file itself was not edited.
- **No i18n surface.** The hook has no user-facing strings, as expected for this task.
- **No consumer yet.** Nothing imports the hook; Task 12 (PaymentForm / SplitPaymentForm / RecordPaymentModal) is its gate, per the plan's Step 3 ("Gate with Task 12").

---

# Gate r1 — verdict CHANGES, applied in commit `c2c9cf2c7`

All four requested items applied on `lane/rh-t11-idempotency-hook`, one path-scoped commit. The plan file was not touched.

## 1. Relocation

```
git mv apps/web/src/lib/hooks/useIdempotencyKey.ts      apps/web/src/hooks/useIdempotencyKey.ts
git mv apps/web/src/lib/hooks/useIdempotencyKey.test.tsx apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx
rmdir apps/web/src/lib/hooks
```

The import in the test became `../useIdempotencyKey`. `apps/web/src/lib/` now contains `hooks.ts` and no `hooks/` directory. Git recorded the implementation as a pure rename — `rename apps/web/src/{lib => }/hooks/useIdempotencyKey.ts (100%)` — so the hook body is byte-identical to the reviewed version.

**The reviewer's stated risk was real, not hypothetical.** `apps/web/src/lib/hooks.ts` is an existing module exporting `useDebouncedValue`, and eight modules import it as `@/lib/hooks`:

```
src/features/documents/to-bill/ToBillPage.tsx:21
src/features/treasury/statements/ReconciliationWorkspacePage.tsx:15
src/components/molecules/pickers/{Bank,Partner,User,Product,Vehicle,Service}Picker.tsx
```

A `hooks.ts` file and a `hooks/` directory as siblings is exactly the ambiguity worth removing.

## 2. Test additions

`src/hooks/__tests__/useIdempotencyKey.test.tsx` now has three cases: the original retain-until-reset case, `gives every mount its own key` (two independent `renderHook` mounts, keys must differ), and `keeps a stable reset identity across a rerender` (`toBe` on the function reference). The regex is hoisted to a `UUID_V4` const pinned to the v4 shape `/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/` and is also applied to the post-reset key.

**Mutation check** — I did not take the new per-mount test on trust. Temporarily hoisting the UUID to module scope (`const SHARED = crypto.randomUUID()`, `useState(SHARED)`) produced:

```
   ✓ useIdempotencyKey > keeps one UUID until reset 8ms
   × useIdempotencyKey > gives every mount its own key 4ms
     → expected '0199c4cd-…-7eecacc3ac0d' not to be '0199c4cd-…-7eecacc3ac0d' // Object.is equality
   ✓ useIdempotencyKey > keeps a stable reset identity across a rerender 1ms
      Tests  1 failed | 2 passed (3)
```

Exactly the module-scope failure the gate asked for, caught by exactly the intended assertion and no other. The implementation was then restored and re-verified green.

## 3. Verification output

**a. Moved suite** — `cd apps/web && pnpm vitest run src/hooks/__tests__/useIdempotencyKey.test.tsx`

```
 ✓ src/hooks/__tests__/useIdempotencyKey.test.tsx (3 tests) 23ms

 Test Files  1 passed (1)
      Tests  3 passed (3)
   Duration  3.63s (transform 770ms, setup 1.65s, collect 357ms, tests 23ms, environment 855ms, prepare 135ms)
```

**b. Typecheck** — `cd apps/web && pnpm typecheck`

```
> @autoerp/web@0.1.0 typecheck
> tsc --noEmit
TYPECHECK_EXIT=0
```

Whole-project, zero diagnostics. This is itself a resolution proof: `tsc` resolves `@/lib/hooks` for all eight consumers.

**c. Lint** — `cd apps/web && pnpm eslint src/hooks/useIdempotencyKey.ts src/hooks/__tests__/useIdempotencyKey.test.tsx`

```
ESLINT_EXIT=0
```

No output = zero errors, zero warnings.

**d. `@/lib/hooks` still resolves** — `cd apps/web && pnpm vitest run src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx`

```
 ✓ src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx (6 tests) 324ms

 Test Files  1 passed (1)
      Tests  6 passed (6)
```

**Caveat the reviewer should know:** this suite is a WEAK proof of that specific claim. It mocks the only picker the page imports — `vi.mock('@/components/molecules/pickers/PartnerPicker', …)` at line 35 of the test — so the real `PartnerPicker`, and therefore its `@/lib/hooks` import, is never loaded on this path. It passes, but it would also pass if resolution were broken.

So I added a proof that actually exercises the import:

`cd apps/web && pnpm vitest run src/components/molecules/pickers/PartnerPicker.test.tsx src/components/molecules/pickers/ProductPicker.test.tsx`

```
 ✓ src/components/molecules/pickers/PartnerPicker.test.tsx (13 tests) 658ms
   ✓ PartnerPicker > debounces the search and issues a request after the user types  302ms
 ✓ src/components/molecules/pickers/ProductPicker.test.tsx (17 tests) 1052ms

 Test Files  2 passed (2)
      Tests  30 passed (30)
```

These load the real pickers, and the named debounce test exercises `useDebouncedValue` from `@/lib/hooks` end to end. Together with the green whole-project `tsc`, `@/lib/hooks` is proven intact after the directory removal.

## 4. Residual notes for the reviewer

- The `crypto.randomUUID()` secure-context caveat from the original handback still stands and is still unaddressed by this lane.
- Still no consumer; Task 12 remains the gate. Any consumer must import `@/hooks/useIdempotencyKey`.
- Final path deviates from the plan text (`apps/web/src/lib/hooks/useIdempotencyKey.ts`) on the reviewer's instruction. The plan file was deliberately left unedited, so it and the tree now disagree on this path — worth a plan erratum if Task 12 is dispatched from the plan text verbatim.
