# Opus Review Prompt — web.tanstack-keys B64-B67

Review the Codex implementation for B64-B67 on `feat/tenant-isolation-sweep-execution`.

Fix commit: `105c508a`

Scope:
- B64: `apps/web/src/features/catalog/api/queries.ts` — 8 callsites `.045-.052`
- B65: `apps/web/src/features/catalog/hooks/useCompositeItems.ts` — 7 callsites `.055-.061`
- B66: `apps/web/src/features/catalog/hooks/useModifierGroups.ts` — 10 callsites `.062-.071`
- B67: `apps/web/src/features/catalog/hooks/useRecipes.ts` — 22 callsites `.072-.093`

Review axes:
- Same pattern as prior approved batches: state-value tenant/company selectors, `tenantScopedKey([...factory])` read keys, enabled gates, predicate invalidation for suffix-scoped cascades, async/awaited invalidations.
- Confirm scanner delta is exactly 47: `275 -> 228`; target files have zero remaining scanner violations.
- Confirm the shared regression suite covers read suffixes/gates, per-call refetch counters, production hook mutation cascades, and tenant-B cache isolation.
- Confirm inventory status is `under_review` for all 47 callsites with fix commit `105c508a`.

Gates already run by Codex:
- `pnpm --filter @autoerp/web test -- src/features/catalog/hooks/__tests__/tenantScope.test.tsx` — PASS (React Query act warnings only, same class as prior batches)
- `pnpm --filter @autoerp/web typecheck` — PASS
- `node apps/web/tools/audit-tanstack-keys.mjs --json` — 228 remaining
- `cd apps/api && php artisan sweep:inventory:verify-history` — 5,130 events / 1,205 callsites / 0 problems

Expected verdict if axes hold: APPROVE.
