Commit reviewed: 2fa5edbd
Verdict: APPROVE

## Round-1 Closure

[F1] closed at 2fa5edbd. `useCategories.ts` now uses `categoriesInvalidationPredicate` to match category namespace plus trailing tenant/company scope and gates by all/lists/trees/detail shape (lines 63-82). The 7 mutation invalidations now use `predicate:` and await invalidation promises: create (146-149), update (168-183), delete (198-201), reorder (220-228).

[F2] closed at 2fa5edbd. Tests now count real `mockApiGet` refetches after create/update/delete/reorder and verify cross-tenant cache remains untouched (test lines 171-338). Deleting a predicate changes expected refetch counts, so the tests are no longer vacuous.

## New Findings (if any)

None.

## Verification Run

- `git diff ed238367..2fa5edbd --name-only`: category hook test, category hook, sweep inventory yml.
- Relevant paths are unchanged from `2fa5edbd` to current HEAD.
- `pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx` (with `--configLoader runner --pool threads`): passed, 9 tests.
- `pnpm typecheck`: passed.
- `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'`: `839` (unchanged from round-1; predicate-based invalidates not seen by scanner).
- `pnpm vitest run src/__tests__/architecture/queryKeyNamespace.test.ts`: passed, 4 tests.
- `pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts`: passed, 9 tests.
- `php artisan sweep:inventory:verify-history`: `verified 2834 event(s) across 1205 callsite(s); 0 problem(s).`
