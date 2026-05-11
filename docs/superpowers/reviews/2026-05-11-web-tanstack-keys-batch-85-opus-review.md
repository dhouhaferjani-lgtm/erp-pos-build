# web.tanstack-keys Batch 85 — Opus Review

Commit reviewed: 9c3529a5
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `9c3529a5` — fix(tenant-isolation): wrap web.tanstack-keys batch 85 (company provider)
Scope: 3 callsites in `apps/web/src/features/company/CompanyProvider.tsx`.
- .105 read `['user', 'companies']`
- .106 removeQueries on logout (predicate path)
- .107 invalidate inside `useInvalidateCompanies` hook

Test: `apps/web/src/features/company/__tests__/CompanyProvider.tenantScope.test.tsx` (new, 187 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: Read at .105 uses `tenantScopedKey(['user', 'companies'])`. Invalidate at .107 mirrors the same wrap. RemoveQueries at .106 uses a dedicated `userCompaniesPredicate` that matches any key starting with `['user', 'companies', ...]` — appropriate because logout may need to clear caches for tenants other than the current one (e.g., partial-hydration races).
2. **State-value selector**: Only `tenantId` is selected (no companyId) because `/user/companies` returns the list — companyId is not known yet.
3. **Enabled gate**: AND-combines existing `isAuthenticated && !isAdminRoute` with `tenantId !== null`. CompanyId is deliberately omitted (see #2).
4. **useInvalidateCompanies subscribes to stores**: The hook calls `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)` without assigning their results. This is intentional — `tenantScopedKey` reads via `getState()` so the closure does not need captured values, but the hook subscriptions force a re-render in the consumer when tenant/company changes, ensuring any sibling queries reset properly. Pattern is consistent with the helper's documentation block.

## Non-blocking findings

1. The two unused selector calls in `useInvalidateCompanies` may read as dead expressions to a future reviewer. A short comment ("// subscribe to force consumer re-render on tenant switch") would prevent accidental removal by a lint pass.
2. The removeQueries predicate path is wider than the closest namespace check (e.g., it would match a `['user', 'companies', 'extra']` key shape that doesn't currently exist) — defensive and harmless.

## Locks applied

3 callsites locked at fix commit `9c3529a5`:
web.tanstack-keys.105, .106, .107.
