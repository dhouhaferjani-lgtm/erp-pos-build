Commit reviewed: 12a75368

# Opus review prompt - web.tanstack-keys batch 26 (parapharmacy pages)

Review the Codex implementation for batch 26.

Scope:
- `web.tanstack-keys.407-.426`
- `apps/web/src/features/parapharmacy/pages/*`
- 20 callsites total across certifications, health claims, ingredients, and key components.

Expected pattern:
- Page query keys are wrapped with `tenantScopedKey([...])`.
- Every touched page reads `tenantId` and `companyId` via state-value selectors.
- Query enabled gates require tenant + company; edit pages also require edit mode.
- Mutation success handlers are async and await list invalidation.
- Shared `parapharmacyListInvalidationPredicate()` gates on namespace, resource, numeric list page slot, tenant suffix, and company suffix; it rejects detail keys.

Codex gates already run:
- `cd apps/web && pnpm vitest run src/features/parapharmacy/pages/__tests__/tenantScope.test.tsx` -> PASS (4 tests)
- `cd apps/web && node tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` -> `580`
- Scanner delta: `600 -> 580` (20 removals)
- `cd apps/web && pnpm typecheck` -> PASS
- `cd apps/api && php artisan sweep:inventory:verify-history` -> PASS (`3869 event(s) across 1205 callsite(s); 0 problem(s)` before submit, re-run after submit clean expected)
- `git diff --check` -> PASS

Review notes:
- The focused test renders all real list/edit pages and checks scoped list/detail key shapes.
- Create/update are driven through the production form pages with per-resource list counters (`1 -> 2 -> 3`).
- Delete pages use the same `parapharmacyListInvalidationPredicate`; the test covers the predicate directly because the confirmation-dialog interaction is not the behavior under review here.
- L18 pre-seeds a tenant-B certifications list cache slot and proves tenant-A list data is clean while tenant-B cache survives.

Review axes:
1. Scanner delta exactly matches `.407-.426`.
2. State-value selectors are present in all eight page files.
3. Predicate is narrow and excludes detail keys.
4. Mutation cascades await active list invalidation.
5. Cross-tenant isolation test proves data separation and tenant-B cache preservation.

If approved, lock these callsites against the fix commit:

```bash
for id in $(seq 407 426); do
  php artisan sweep:inventory:review \
    --callsite-id="web.tanstack-keys.$id" \
    --actor=claude \
    --verdict=fixed \
    --review-commit=12a75368 \
    --review-file=../../docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-26-opus-review.md
done
```
