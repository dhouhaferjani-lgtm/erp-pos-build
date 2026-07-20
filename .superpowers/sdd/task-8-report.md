# Task 8 report — `locationScopedKey` helper + audit approval

Status: **DONE**

## Base/head

- Base: `613808cf075ce0db28e62c8ea3f9fc59382f4346`
- Head: `a0ef2191d` (`feat(multiloc): locationScopedKey helper + audit approval (§1 FE)`)

## Outcome

- Added `apps/web/src/lib/locationScopedKey.ts` with the pinned non-leading
  `{ locScope }` segment and deterministic sorting for selected locations.
- Added focused helper tests for resource-prefix preservation, scope sorting,
  and the `'all'` literal.
- Approved `locationScopedKey` in the TanStack key audit factory set.
- Added audit coverage for useQuery approval and cache-filter rejection. Cache
  filters remain rejected because tenant/company suffixes cannot match a bare
  React Query prefix.

## TDD evidence

### RED

Command:

```text
cd apps/web && pnpm vitest run src/lib/locationScopedKey.test.ts tools/__tests__/audit-tanstack-keys.test.mjs
```

Failed as expected: the helper import was missing and the new audit approval
case reported one unapproved `locationScopedKey` query key.

### GREEN

The same command after implementation passed:

```text
Test Files  2 passed (2)
Tests  51 passed (51)
```

## Verification

- `cd apps/web && pnpm audit:keys`: passed; 0 new and 0 stale violations.
- `cd apps/web && pnpm typecheck`: passed.
- Scoped ESLint over all four touched files: passed with 0 errors and 3
  expected unsafe-cast warnings from the pinned helper/test assertions.
- Full `pnpm lint:eslint` was attempted, but duplicate long-running ESLint
  processes produced no result after roughly two minutes; only those own
  processes were terminated. No formatter is installed in `apps/web`.
- `git diff --check`: passed.
- `npx react-doctor@latest --scope changed --verbose`: score 49/100 with 257
  diagnostics across pre-existing files; no finding pointed to the Task 8
  helper or audit files. The scan's `--diff` alias is deprecated.

## Deviations/concerns

- The requested report path is normally ignored, but an older tracked report
  already existed at this path in the branch; this report update is therefore
  intentionally left outside the Task 8 commit.
- React Doctor's broad changed-file scan reports pre-existing repository
  diagnostics and does not indicate a Task 8 regression.
