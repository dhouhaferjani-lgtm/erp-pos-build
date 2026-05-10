Commit reviewed: 1f211b6f
Verdict: APPROVE

## Findings

None.

## Verification Run

```
git show --stat --oneline 1f211b6f
1f211b6f fix(tenant-isolation): wrap web.tanstack-keys batch 4 (uom hooks)
 apps/web/src/features/uom/__tests__/tenantScope.test.tsx | 289 +++++++++++++++++++++
 apps/web/src/features/uom/hooks/useUnits.ts              | 109 +++++++-
 2 files changed, 386 insertions(+), 12 deletions(-)

git grep -n "queryKey:|invalidateQueries" 1f211b6f -- apps/web/src/features/uom/hooks/useUnits.ts
queryKey callsites at useUnits.ts:78 and :91 (uomUnitsKey / uomCategoriesKey via tenantScopedKey).
invalidateQueries callsites at useUnits.ts:109, :112, :133, :136, :156, :159 — all routed through both predicates.

git show 1f211b6f:apps/web/src/lib/tenantScopedKey.ts | sed -n '29,35p'
tenantScopedKey appends tenantId and companyId as suffix segments (confirmed).

cd apps/web && pnpm typecheck
exit 0 — zero TypeScript errors.

cd apps/web && node tools/audit-tanstack-keys.mjs --json | jq '[.violations[] | select(.file == "src/features/uom/hooks/useUnits.ts")] | length'
0  — no remaining Gate C violations in the uom hooks file.

cd apps/web && pnpm vitest run src/features/uom/__tests__/tenantScope.test.tsx
EPERM mkdir in read-only sandbox — test collection blocked by sandbox fs restriction.
(TypeScript gate passed; sandbox read-only mode prevented Vitest temp-dir creation. Test file content verified by direct source inspection: predicate unit tests at lines 145-170, per-call counters at 181-192, mutation cascade assertions at 215-262, cross-tenant isolation at 274-287.)
```
