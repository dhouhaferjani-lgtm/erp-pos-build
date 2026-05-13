# Opus Review Prompt — web.tanstack-keys B68

Review the Codex implementation for B68 on `feat/tenant-isolation-sweep-execution`.

Fix commit: `54b7e9a0`

Scope:
- B68: `apps/web/src/features/enrichment/api/enrichmentQueries.ts` — 5 callsites `.250-.254`

Review axes:
- Same pattern as prior approved hook batches: state-value tenant/company selectors, `tenantScopedKey([...factory])` read keys, enabled gates, predicate invalidation for suffix-scoped cascades, async/awaited invalidations.
- Confirm scanner delta is exactly 5: `228 -> 223`; target file has zero remaining scanner violations.
- Confirm the regression suite covers read suffixes/gates, per-call refetch counters, production hook mutation cascade, and tenant-B cache isolation.
- Confirm inventory status is `under_review` for `.250-.254` with fix commit `54b7e9a0`.

Gates already run by Codex:
- `pnpm --filter @autoerp/web test -- src/features/enrichment/api/__tests__/enrichmentQueries.tenantScope.test.tsx` — PASS (React Query act warnings only)
- `pnpm --filter @autoerp/web typecheck` — PASS
- `node apps/web/tools/audit-tanstack-keys.mjs --json` — 223 remaining
- `cd apps/api && php artisan sweep:inventory:verify-history` — 5,145 events / 1,205 callsites / 0 problems

Expected verdict if axes hold: APPROVE.
