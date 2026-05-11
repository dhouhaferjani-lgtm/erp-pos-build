# Codex review prompt — web.tanstack-keys batch 1 (callsites .095-.104)

You are reviewing the first per-batch fix-cycle of the `web.tanstack-keys` cluster (master plan §11). The cluster's foundation chain (helper, scanner, seed, foundation tests) was Codex round-2 APPROVE'd at `de2a3d24`; this batch is the first of ~85 expected per-batch fixes.

## Self-disclosed history (read this first)

The main session submitted callsites .095-.104 at commit **ed238367** and then, while writing a cascade-verification test, **self-discovered a prefix-match cascade defect** in the original implementation. Self-fixed at commit **2fa5edbd** (current branch tip).

The defect: invalidations used `tenantScopedKey([...categoryKeys.X(...)])` as the queryKey arg. Because `tenantScopedKey()` appends `tenant_id` and `company_id` to the SUFFIX, an invalidation tag like `tenantScopedKey([...categoryKeys.all])` resolves to `['categories', tenantId, companyId]`. TanStack does positional prefix-matching, and that key is **not a prefix** of the leaf cache entries `['categories', 'list', params, tenantId, companyId]` because position 1 is `tenantId` vs `'list'`. Therefore the mutations did NOT actually invalidate dependent queries.

The fix at `2fa5edbd`: switch all 7 invalidations in the 4 mutation hooks to **predicate-based** form (`invalidateQueries({ predicate: ... })`). The audit-tanstack-keys scanner only inspects `queryKey:` properties, so predicate-based invalidations don't register as violations — scanner count stays at 839. The new predicates match `q.queryKey[0] === 'categories' && k.at(-2) === tenantId && k.at(-1) === companyId` plus shape-specific gates (lists/trees/detail(id)).

Also at `2fa5edbd`: `onSuccess` made `async` and `await`s the invalidate Promise so callers see fresh data after `mutateAsync` resolves; cascade tests verify by counting `mockApiGet` invocations rather than `isInvalidated` (which TanStack resets after refetch).

## Scope

10 callsites in `apps/web/src/features/categories/hooks/useCategories.ts`:

| callsite_id | line | symbol | pattern_type |
|-------------|-----:|--------|--------------|
| web.tanstack-keys.095 | 47  | useCategories     | querykey_usequery_call_expression |
| web.tanstack-keys.096 | 58  | useCategoryTree   | querykey_usequery_call_expression |
| web.tanstack-keys.097 | 69  | useCategory       | querykey_usequery_call_expression |
| web.tanstack-keys.098 | 89  | onSuccess (create)| querykey_invalidatequeries_other |
| web.tanstack-keys.099 | 108 | onSuccess (update)| querykey_invalidatequeries_call_expression |
| web.tanstack-keys.100 | 109 | onSuccess (update)| querykey_invalidatequeries_call_expression |
| web.tanstack-keys.101 | 110 | onSuccess (update)| querykey_invalidatequeries_call_expression |
| web.tanstack-keys.102 | 125 | onSuccess (delete)| querykey_invalidatequeries_other |
| web.tanstack-keys.103 | 144 | onSuccess (reorder)| querykey_invalidatequeries_call_expression |
| web.tanstack-keys.104 | 145 | onSuccess (reorder)| querykey_invalidatequeries_call_expression |

## What the workflow needs from you

The review CLI requires `--review-commit` to match the callsite's stored `fix_commit`, which was set at submit time to `ed238367`. The current branch tip is `2fa5edbd`. To unstick:

**Round 1 (this round):** verdict `REQUEST-CHANGES` against `ed238367`. Cite the prefix-match cascade defect as your finding (or whatever you find — but the defect IS real, the main session verified it with a failing cascade test). The CLI will move all 10 callsites back to `in_progress`. Save your verdict file at `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-1-codex-review.md`.

**Round 2 (after main session re-submits at the fix commit):** verdict `APPROVE` (or `APPROVE-WITH-MINOR-EDITS-APPLIED`) against `2fa5edbd`. Save your verdict file at `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-1-codex-rereview.md`.

The two-round structure is the formal way to update `fix_commit` from `ed238367` → `2fa5edbd`. Don't skip round 1 just because the defect was self-found — the audit trail needs the round-1 verdict file recording the discovered defect.

## What to actually review at 2fa5edbd

1. **Read** `apps/web/src/features/categories/hooks/useCategories.ts` end-to-end. Confirm:
   - All 10 callsites correctly tenant-scoped (3 useQuery via `tenantScopedKey([...])`, 7 invalidate via `predicate: ...`).
   - The 3 useQuery hooks subscribe to `useAuthStore` + `useCompanyStore` via selector form for causal recomputation, AND have `enabled: !!tenantId && !!companyId` defense-in-depth gates.
   - The 4 mutation hooks subscribe to both stores so the closure captures the current tenant scope, AND `onSuccess` is `async` and `await`s the invalidate Promise (so callers see fresh data after `mutateAsync`).
   - The `categoryKeys` factory remains un-scoped (un-changed structural prefixes).
   - The `categoriesInvalidationPredicate` helper logic — does it correctly handle the suffix-position scope, the tenant/company tail, and the shape-specific gates?

2. **Read** `apps/web/src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx`. Confirm 9 tests cover:
   - queryKey shape includes tenant_id + company_id (.095-.097)
   - queryKeys differ across tenants
   - queryKeys differ across companies
   - useCreateCategory refetches all (.098, fetch-count signal)
   - useUpdateCategory refetches detail+lists+trees but NOT detail(99) (.099-.101, fetch-count signal)
   - useDeleteCategory refetches all (.102)
   - useReorderCategories refetches lists+trees but NOT details (.103-.104)
   - cross-tenant invariant: tenant-A mutation does NOT touch tenant-B cache
   - factory contract pinned

3. **Hostile-grep** the file for any other queryKey shape that might have been missed. The scanner says 10; verify by reading the file end-to-end.

4. **Test honesty**: would each test still pass if I deleted just one tenantScopedKey wrap or one predicate from the production file? If yes, the test is vacuous on that callsite.

5. **Any other defects** the main session may have missed.

## Quality gates the main session ran at 2fa5edbd

```text
pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx
  → 9 tests passed

pnpm typecheck → pass

node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
  → 839 (was 849 before batch 1; delta = 10, exact batch size)

pnpm vitest run src/__tests__/architecture/queryKeyNamespace.test.ts
  → 4 tests passed (no regression)

pnpm vitest run src/lib/__tests__/tenantScopedKey.test.ts
  → 9 tests passed (foundation regression check)

php artisan sweep:inventory:verify-history
  → verified 2814 event(s) across 1205 callsite(s); 0 problem(s).
```

## Deliverable

Save your verdict to `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-1-codex-review.md` for round 1.

Verdict file format (strict, parsed by `SweepInventoryReviewCommand`):

```text
Commit reviewed: <single-SHA>
Verdict: <APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCKER>

## Findings

[F1] ...
[F2] ...
```

The `Verdict:` line MUST be one of those four exact strings, used verbatim in the lock-time CLI flag. The `Commit reviewed:` SHA must equal the `--review-commit` flag the lock CLI is invoked with — for round 1, that's `ed238367`; for round 2, that will be `2fa5edbd`.
