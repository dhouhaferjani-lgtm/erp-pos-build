# Codex review prompt — web.tanstack-keys batch 4 (callsites .740-.747)

You are reviewing batch 4 of the `web.tanstack-keys` cluster (master plan §11). Batches 1+2+3 locked at `60a6f598` (33/849 fixed). This batch covers `apps/web/src/features/uom/hooks/useUnits.ts` — 8 callsites, single-file factory pattern (mirrors batch 1's categoryKeys structure).

## Scope (8 callsites, 1 file)

| callsite | line | symbol | shape | fix |
|----------|-----:|--------|-------|-----|
| .740 | 21 | useCategories | useQuery factory call | tenantScopedKey([...uomKeys.categories()]) + enabled gate |
| .741 | 31 | useUnits | useQuery factory call | tenantScopedKey([...uomKeys.unitsByCategory(categoryId)]) + enabled gate |
| .742 | 45 | useCreateUnit.onSuccess | invalidate uomKeys.units() | predicate uomUnitsInvalidationPredicate |
| .743 | 46 | useCreateUnit.onSuccess | invalidate uomKeys.categories() | predicate uomCategoriesInvalidationPredicate |
| .744 | 61 | useUpdateUnit.onSuccess | invalidate uomKeys.units() | predicate uomUnitsInvalidationPredicate |
| .745 | 62 | useUpdateUnit.onSuccess | invalidate uomKeys.categories() | predicate uomCategoriesInvalidationPredicate |
| .746 | 76 | useDeleteUnit.onSuccess | invalidate uomKeys.units() | predicate uomUnitsInvalidationPredicate |
| .747 | 77 | useDeleteUnit.onSuccess | invalidate uomKeys.categories() | predicate uomCategoriesInvalidationPredicate |

## Fix at commit `1f211b6f` (current branch tip)

Same factory pattern as batch 1 (categoryKeys) — the `uomKeys` factory is preserved un-scoped, useQuery sites wrap with `tenantScopedKey([...uomKeys.X(...)])`, mutation invalidations switch to predicate-based form because the wrap-in-factory approach hits the prefix-cascade defect.

Two new exported predicates from `useUnits.ts`:
- `uomUnitsInvalidationPredicate(t, c)` — matches `[uom, units, ...]` queries with the active tenant tail.
- `uomCategoriesInvalidationPredicate(t, c)` — matches `[uom, categories, ...]` queries.

Both used by all 3 mutation hooks (create/update/delete) — each fires both predicates in parallel via `await Promise.all([...])`.

## What you should adversarially check

1. **Predicate correctness** — walk through each predicate's positive and negative cases. The predicate test cases pin both:
   - positive: matches `[uom, units, { categoryId: 'cat-1' }, t, c]` and `[uom, units, { categoryId: undefined }, t, c]`.
   - negative: rejects `[uom, categories, t, c]` (k[1] mismatch), wrong tenant/company, `[products, ...]`.

2. **Closure capture of tenant scope** — all 5 hooks (2 useQuery + 3 mutations) subscribe to `useAuthStore` + `useCompanyStore` via state-value selectors (not action selectors that were F1 in batch 2). Mutation closures capture closure-fresh tenantId/companyId.

3. **`enabled` defense-in-depth gate** — both useQuery hooks gate by `!!tenantId && !!companyId`.

4. **Test honesty (cascade signals)** — the 3 cascade tests use per-call counters in `mockApiGet.mockImplementation` (categoriesCalls, unitsCalls). Post-mutation, both counters should be 2. An always-false predicate would leave them at 1 → test fails. Mental check: would the test fail if I removed `uomCategoriesInvalidationPredicate` from `useCreateUnit.onSuccess`? Yes — categoriesCalls stays at 1, the `expect(getCounters().c()).toBe(2)` assertion fails.

5. **Cross-tenant isolation** — the 4th cascade test seeds a tenant-B units cache entry, fires a tenant-A create, and asserts the tenant-B entry is untouched.

6. **Hostile-grep** — confirm only the 8 expected callsites are touched. Scanner says 816 → 808 delta = 8.

7. **No regressions** in batches 1+2+3 + foundation tests.

## Quality gates the main session ran at `1f211b6f`

```text
pnpm vitest run src/features/uom/__tests__/tenantScope.test.tsx                     → 12/12 pass
pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope   → 9/9 pass (B1)
pnpm vitest run src/features/products/__tests__/tenantScope.test.tsx                → 13/13 pass (B2)
pnpm vitest run src/pages/POS/__tests__/tenantScope.test.tsx                        → 12/12 pass (B3)
pnpm vitest run src/__tests__/architecture/queryKeyNamespace.test.ts                → 4/4 pass
pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts                           → 9/9 pass
pnpm typecheck → pass
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'     → 808 (was 816; delta = 8)
php artisan sweep:inventory:verify-history → 3030 events / 1205 callsites / 0 problems
```

## Deliverable

Save your verdict to `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-4-codex-review.md`.

Format (strict):

```text
Commit reviewed: 1f211b6f
Verdict: <APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCKER>

## Findings

[F1] ...

## Verification Run

(any commands you ran)
```

The `Verdict:` line MUST equal one of those four exact strings.

Single-round APPROVE expected — same factory + predicate pattern as batches 1+2+3 (validated 3 times now), cascade tests use the established fetch-count signal pattern from B3-round-1 lesson. If you find a substantive defect, REQUEST-CHANGES is fine; main session will iterate.

If sandbox blocks file writes, report findings + verdict in your final message — main session will persist the verdict file.
