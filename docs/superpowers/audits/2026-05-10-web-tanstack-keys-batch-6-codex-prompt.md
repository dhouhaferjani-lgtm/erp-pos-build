# Codex review prompt — web.tanstack-keys batch 6 (coupons hooks)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `e284c4e5`
**File under review:** `apps/web/src/features/coupons/hooks/useCoupons.ts`
**Test:** `apps/web/src/features/coupons/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.120`–`.126` (7)
**Cluster:** `web.tanstack-keys` (was 800; this batch closes 7 → 793 remaining if APPROVE)

## Context (compressed — direct B5 mirror)

Same pattern as B5 applied to coupons. `COUPONS_KEY = ['coupons']` constant, 2 useQuery + 5 invalidate (create, update, delete, revoke, reactivate). `useValidateCoupon` stays unchanged — no cache invalidate.

The fix: wrap the 2 useQueries with `tenantScopedKey([...COUPONS_KEY, params|id])` + state-value selectors + enabled gate. Switch the 5 invalidates to `predicate: couponsInvalidationPredicate(tenantId, companyId)`, exported from `useCoupons.ts`. Predicate mirrors `promotionsInvalidationPredicate` byte-for-byte except the namespace literal.

## What to verify

1. Scanner delta = 7 (`audit-tanstack-keys.mjs` count drops from 800 to 793). **Confirmed live: 793.**
2. State-value selectors used (subscribe to `s.user?.tenant_id` and `s.currentCompanyId`).
3. Predicate matches list `[coupons, {}, t, c]`, params-list `[coupons, {search:'x'}, t, c]`, detail `[coupons, 'c-123', t, c]`; rejects wrong-t/c/namespace/short-key.
4. Cascade tests use per-call counters; would fail with always-false predicate.
5. Cross-tenant isolation test seeds tenant-B cache + asserts `state.isInvalidated === false` post-mutation.
6. Each mutation's `onSuccess` is async + awaits the invalidate.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/coupons/__tests__/tenantScope.test.tsx`: 13/13 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3091 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-6-codex-review.md
```

First non-empty line: `Commit reviewed: e284c4e5`. Verdict line: `Verdict: APPROVE`, `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED`, or `Verdict: REQUEST-CHANGES`.

Same shape as B5, expected verdict APPROVE.
