# HANDBACK — Request Hygiene Phase A, Task 11 (shared idempotency-key hook)

- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 11: Shared idempotency-key hook (ID-1..ID-4 foundation)`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t11`
- **Branch:** `lane/rh-t11-idempotency-hook` (based on `dev`, base commit `6f16fd8f7`)
- **Commit:** `d7cc53d53` — code + test + this handback, path-scoped. (A doc-only follow-up commit fills this line in with the hash, since a commit cannot contain its own hash; the branch tip is that follow-up.)
- **Result:** all four checks passed (red run red for the stated reason, green run green, typecheck exit 0, eslint exit 0).

## Files

- Create `apps/web/src/lib/hooks/useIdempotencyKey.ts`
- Create `apps/web/src/lib/hooks/useIdempotencyKey.test.tsx`

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
- **`src/lib/hooks/` is a new directory.** `apps/web/src/hooks/` already exists. The plan explicitly specifies `apps/web/src/lib/hooks/useIdempotencyKey.ts`, so I followed the plan rather than the pre-existing directory. Flagging it in case the reviewer prefers consolidation before consumers land.
- **No i18n surface.** The hook has no user-facing strings, as expected for this task.
- **No consumer yet.** Nothing imports the hook; Task 12 (PaymentForm / SplitPaymentForm / RecordPaymentModal) is its gate, per the plan's Step 3 ("Gate with Task 12").
