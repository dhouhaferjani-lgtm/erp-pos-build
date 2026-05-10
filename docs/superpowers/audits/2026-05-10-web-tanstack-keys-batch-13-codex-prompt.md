# Codex review prompt — web.tanstack-keys batch 13 (services pages)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `904349ec` (production fix; verdict pins here)
**Test expansion (post-submit):** `5ea5d444` — replaced inline probes with direct production-component renders.
**Round-1 BLOCK fix (test only):** `53bebb48` — restored cross-tenant data-leakage assertion that 5ea5d444 silently dropped. Inspect tests at HEAD (53bebb48); production stays at 904349ec.
**Files (4 + helper):**
- `apps/web/src/features/services/_invalidation.ts` (new)
- `apps/web/src/features/services/ServiceCategoryListPage.tsx`
- `apps/web/src/features/services/ServiceDetailPage.tsx`
- `apps/web/src/features/services/ServiceForm.tsx`
- `apps/web/src/features/services/ServiceListPage.tsx`

**Test:** `apps/web/src/features/services/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.612`–`.624` (13)
**Cluster:** was 736; this batch closes 13 → 723 if APPROVE.

## Context (compressed — page-level B9 mirror, 3 namespaces)

3 namespaces:
- `services` (plural list) — predicate-based invalidate
- `service` (singular detail) — exact-match wrap, no predicate (B3 lesson 4 reapplied)
- `service-categories` — predicate-based invalidate

4 page files share the helper module `_invalidation.ts`. ServiceListPage owns 2 useQuery (categories + services); ServiceCategoryListPage owns categories useQuery + 3 mutations all targeting categories; ServiceDetailPage owns singular service useQuery + 1 services-namespace invalidate (delete); ServiceForm owns 2 useQuery (categories + singular service) + 3 invalidates (2 services + 1 singular service exact-match).

Cross-tenant test applies B12 round-1 BLOCK lesson upfront: pre-seed tenant-B services data, render a tenant-A useQuery, assert tenant-A's query result equals the empty tenant-A mock response (NOT the seeded tenant-B payload).

## What to verify

1. Scanner delta = 13 (`audit-tanstack-keys.mjs` count drops from 736 to 723). **Confirmed live: 723.**
2. State-value selectors used in all 4 pages.
3. 2 predicates correctly gate on `k[0] === <namespace>` AND tail t/c. Each rejects the other invalidatable namespace + the singular `service` namespace.
4. Singular `service` invalidate (ServiceForm update mutation) uses exact-match `tenantScopedKey(['service', id])` — same shape as the leaf useQuery, no predicate.
5. Cross-tenant cache isolation (tenant-B cache survives tenant-A cascade) AND cross-tenant DATA isolation (tenant-A query results don't contain tenant-B entries) — both asserted.
6. Mutations' onSuccess async + awaited; navigate() runs after invalidate.

## Quality gate evidence

- `pnpm vitest run src/features/services/__tests__/tenantScope.test.tsx`: 14/14 passing.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3365 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-13-codex-review.md
```

**Critical format:** First non-empty line MUST be `Commit reviewed: 904349ec`. Verdict line MUST be a literal `Verdict: APPROVE` (or `Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED` / `Verdict: REQUEST-CHANGES` / `Verdict: BLOCK`) on its own line — NOT `## VERDICT:` heading. Use the Write tool to save the file in this turn.
