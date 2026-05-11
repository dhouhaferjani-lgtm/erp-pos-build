Commit reviewed: 51852720

# Opus review — web.tanstack-keys batch 32 (POS products/tables)

Independent second-pair-of-eyes review of the Codex implementation
at `51852720`. All 7 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 11 (514 → 503): PASS.** usePOSProducts (1
useQuery) + useTables (2 useQuery list/floors + 4 floor mutations +
4 table mutations with predicate-based cascade) = 11.

**Gate 2 — All query keys wrapped at callsite: PASS.**
- products: `tenantScopedKey([...posProductKeys.list(hasParams ?
  queryParams : undefined)])`
- floors: `tenantScopedKey([...tableKeys.floors()])`
- tables list: `tenantScopedKey([...tableKeys.tableList(params)])`

**Gate 3 — Hooks subscribe via `usePosTenantScope()`: PASS.** Helper
reused from B30.

**Gate 4 — Product, floor, table queries require tenant/company scope:
PASS.** Each useQuery has `enabled: hasTenantScope` (with optional
upstream `enabled` flag preserved via `(enabled ?? true) &&
hasTenantScope` for usePOSProducts).

**Gate 5 — Floor mutations await exact scoped floor invalidation:
PASS.** Each of 4 floor mutations uses `await invalidateQueries({
queryKey: tenantScopedKey([...tableKeys.floors()]) })`.

**Gate 6 — Table mutations use namespace-aware predicate: PASS.**
Each of 4 table mutations uses `predicate: scopedKeyPredicate('tables',
tenantId, companyId)` — covers all `['tables', ...]` cache entries
for the current tenant/company while preserving other tenants.

**Gate 7 — Tests cover scoped key shapes + no-fetch + per-call counters
+ tenant-B preservation: PASS.** 3 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/hooks/__tests__/posProductsTables.tenantScope.test.tsx`:
  **3/3 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
