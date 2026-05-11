# Codex review prompt — web.tanstack-keys batch 8 (progression hooks)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `fc767fd3`
**Files under review (3):**
- `apps/web/src/features/progression/hooks/useCompanyProgression.ts`
- `apps/web/src/features/progression/hooks/useModuleReadiness.ts`
- `apps/web/src/features/progression/hooks/useRecommendations.ts`

**New test:** `apps/web/src/features/progression/__tests__/tenantScope.test.tsx`
**Updated existing tests:** `apps/web/src/features/progression/hooks/__tests__/{useCompanyProgression,useModuleReadiness,useRecommendations}.test.ts` (added beforeEach to set tenant + company stores so the new enabled gate doesn't suppress queries — minimal scoped change).
**Callsites:** `web.tanstack-keys.565`–`.572` (8)
**Cluster:** `web.tanstack-keys` (was 786; this batch closes 8 → 778 remaining if APPROVE)

## Context (compressed — first multi-predicate cascade batch)

Factory pattern (B1/B4-shape) with `progressionKeys` exported from `useCompanyProgression.ts`. Three distinct predicates needed because the cluster has three sibling namespaces (`profile`, `modules`, `recommendations`) and the cascade boundaries differ:

- `useActivateModule` invalidates **both** `modules` + `profile` (Promise.all of two predicate calls).
- `useAcceptRecommendation` + `useDismissRecommendation` each invalidate **only** `recommendations`.
- `useMilestones` queryKey is wrapped, but **no mutation invalidates milestones** — predicate file does not export a milestones predicate.

This is the first batch with multiple predicates per cluster. The risk: if a predicate is too permissive, an unrelated mutation could falsely invalidate cousin namespaces. The cascade test asserts the negative case for each mutation (e.g., useAcceptRecommendation leaves profile/modules/milestones counters at 1).

## What to verify

1. Scanner delta = 8 (`audit-tanstack-keys.mjs` count drops from 786 to 778). **Confirmed live: 778.**
2. State-value selectors used in all 3 hook files.
3. Each predicate gates on `k[1] === <namespace>` AND tail-segment t/c equality. Cross-namespace negative cases asserted in unit tests.
4. `useActivateModule` cascades BOTH modules + profile via `Promise.all`. Test asserts both counters increment to 2; milestones + recommendations counters stay at 1.
5. `useAcceptRecommendation` + `useDismissRecommendation` cascade ONLY recommendations. Test asserts profile/modules/milestones counters stay at 1.
6. Cross-tenant isolation: tenant-B profile + modules cache entries seeded via `setQueryData` survive a tenant-A activate without `isInvalidated`.
7. Existing hook tests (3 files) have minimal beforeEach setup, no behavior tests changed beyond the tenant/company state setup.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/progression/`: 30/30 passing across 6 files.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3150 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-8-codex-review.md
```

First non-empty line: `Commit reviewed: fc767fd3`. Verdict line MUST be a literal `Verdict: APPROVE` (NOT `## VERDICT:` heading — strict CLI regex).

Multi-predicate cascade is the new wrinkle. If predicate isolation is sound and cascade tests cover the negative cases per mutation, expected verdict is APPROVE.
