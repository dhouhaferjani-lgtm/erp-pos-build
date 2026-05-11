# Codex review prompt — web.tanstack-keys batch 5 (promotions hooks)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `6343f0ba`
**File under review:** `apps/web/src/features/promotions/hooks/usePromotions.ts`
**Test:** `apps/web/src/features/promotions/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.573`–`.580` (8)
**Cluster:** `web.tanstack-keys` (was 808 pending; this batch closes 8 → 800 remaining if APPROVE)

## Context (compressed — you've reviewed 4 batches now)

Same wrap-at-callsite + predicate-based-invalidation pattern as batches 1–4. The only material difference vs B4 (uomKeys factory) is **identifier-shape**: the cluster constant `PROMOTIONS_KEY = ['promotions']` is a bare-Identifier reference, not a key-factory call. The scanner had flagged 2 useQuery (spread+params, spread+id) and 6 invalidate callsites (bare PROMOTIONS_KEY).

The fix: wrap the 2 useQueries with `tenantScopedKey([...PROMOTIONS_KEY, params|id])` + state-value selectors + enabled gate. Switch the 6 invalidates to `predicate: promotionsInvalidationPredicate(tenantId, companyId)`, exported from `usePromotions.ts`. Predicate matches `k[0] === 'promotions' && k.at(-2) === t && k.at(-1) === c`.

## What to verify

1. **Identifier-shape wrap is scanner-approved.** Confirm `node apps/web/tools/audit-tanstack-keys.mjs` count drops by exactly 8 from the prior tip (`abd13eb9`). Should be `808 → 800`.
2. **State-value selectors, not action selectors.** Each hook must subscribe to `(s) => s.user?.tenant_id ?? null` and `(s) => s.currentCompanyId ?? null` — NOT `(s) => s.getCurrentCompany`. The B2 round-1 F1 lesson.
3. **Predicate scope is correct.** `promotionsInvalidationPredicate('tenant-A', 'company-1')` must match list `[promotions, {}, t, c]`, params-list `[promotions, {search:'x'}, t, c]`, AND detail `[promotions, 'p-123', t, c]`, and reject any of: wrong tenant, wrong company, non-promotions namespace, length < 3.
4. **Cascade tests are non-vacuous.** Per-call counters in `mockApiGet.mockImplementation` ensure an always-false predicate would leave counters at 1 and fail deterministically. The B3 round-1 F1 lesson.
5. **Cross-tenant isolation test seeds tenant-B cache via `setQueryData`** and asserts post-mutation `state.isInvalidated === false` AND `state.data` unchanged. The B2 round-2 F2 lesson.
6. **Async onSuccess + Promise.all.** Each mutation's onSuccess is async and awaits the invalidate so `mutateAsync` resolves only after refetches complete.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/promotions/__tests__/tenantScope.test.tsx`: 14/14 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3062 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

Save the review at:

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-5-codex-review.md
```

The first non-empty line MUST be `Commit reviewed: 6343f0ba` (single SHA). The verdict line MUST be one of `Verdict: APPROVE`, `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED`, `Verdict: REQUEST-CHANGES`. If REQUEST-CHANGES, list findings as F1, F2, F3 with file:line references.

Tight pattern check: the only NEW shape this batch is identifier-spread vs B4 factory-call. If wrap + predicate + cascade tests look correct, expected verdict is APPROVE.
