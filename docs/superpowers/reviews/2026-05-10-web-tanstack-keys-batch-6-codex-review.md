Commit reviewed: e284c4e5

# Web TanStack Keys Batch 6 — Codex Review

**Batch:** B6 — coupons/hooks/useCoupons.ts
**Callsites:** web.tanstack-keys.120–.126 (7 callsites)
**Scanner delta:** 800 → 793 (7 fixed)
**Date:** 2026-05-10

---

## Six-Axis Evaluation

STATE-VALUE SELECTORS: PASS
Rationale: Hooks subscribe to scalar tenant/company state via `s.user?.tenant_id ?? null` and `s.currentCompanyId ?? null` in `apps/web/src/features/coupons/hooks/useCoupons.ts:46`, `:47`, `:56`, and `:57`.

SCANNER DELTA = 7: PASS
Rationale: The brief confirms callsites `web.tanstack-keys.120` through `.126` and live count `793`, matching `800 -> 793`.

PREDICATE CORRECTNESS: PASS
Rationale: `couponsInvalidationPredicate` requires namespace `coupons` plus matching tenant/company suffixes in `apps/web/src/features/coupons/hooks/useCoupons.ts:35` through `:40`.

NON-VACUOUS CASCADE: PASS
Rationale: Tests increment list/detail counters and assert both reach `2` after each mutation in `apps/web/src/features/coupons/__tests__/tenantScope.test.tsx:180` through `:304`.

CROSS-TENANT ISOLATION: PASS
Rationale: Keys include tenant/company suffixes in `apps/web/src/features/coupons/hooks/useCoupons.ts:49` and `:59`, and the tenant-B cache remains non-invalidated in `apps/web/src/features/coupons/__tests__/tenantScope.test.tsx:317` through `:326`.

ASYNC onSuccess: PASS
Rationale: All five invalidating mutations use async `onSuccess` and await invalidation in `apps/web/src/features/coupons/hooks/useCoupons.ts:71`, `:88`, `:105`, `:131`, and `:149`.

---

## Diff vs B5

Confirmed structurally equivalent to B5 promotions for tenant-scoped wrapping, predicate invalidation, scalar tenant/company subscriptions, async awaited invalidations, and cascade/isolation tests; expected drift is `coupons` namespace/API names and 5 invalidating mutations instead of 6 because coupons has no archive mutation and `useValidateCoupon` has no cache invalidation.

---

## Quality Gate Evidence (from main session)

- pnpm vitest: 13/13 pass
- pnpm typecheck: clean
- verify-history: 3091 events / 1205 callsites / 0 problems

---

## VERDICT: APPROVE

Verdict: APPROVE
