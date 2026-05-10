Commit reviewed: 38786773
Verdict: APPROVE

## Round-2 Closure

[F2] closed at 38786773. In `apps/web/src/features/products/__tests__/tenantScope.test.tsx`, the `useProductRealtime production cascade through handleUpdate` block now uses a non-zero-GC query client at lines 430-436 (`gcTime: Infinity`), renders the production `useProducts` + `useProductRealtime` path at lines 443-449, and seeds the tenant-scoped singular cache entry `['product', 'prod-1', 'tenant-A', 'company-1']` with `queryClient.setQueryData(...)` at lines 459-464. The simulated backend event is fired through the captured production callback at lines 472-484. The test then asserts the predicate/list path via `mockApiGet` moving to 2 calls at lines 486-490 and asserts the singular entry remains invalidated at lines 492-499. This closes the round-2 gap: if the production singular invalidate at `apps/web/src/features/products/hooks/useProductRealtime.ts:83-85` were removed, the seeded singular query would remain `state.isInvalidated === false` and the assertion at line 499 would fail.

## New Findings (if any)

None.

## Verification Run

`git rev-parse 38786773`

Output:
```text
387867733228639fb867198a2824be6081048950
```

`git diff --name-only 38786773..HEAD -- apps/web/src/features/products apps/web/src/features/categories/hooks apps/web/src/lib apps/web/tools/audit-tanstack-keys.mjs`

Output:
```text
(empty — no diffs on these paths between 38786773 and HEAD)
```

`node tools/audit-tanstack-keys.mjs --json | node -e "const d=JSON.parse(require('fs').readFileSync('/dev/stdin','utf8'));console.log(d.violations.length)"` from `apps/web`

Output:
```text
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 827
827
```

Vitest runs blocked by EPERM on sandbox `.vite-temp` writes; test counts not confirmed via direct execution. Typecheck passed:

`pnpm typecheck` from `apps/web`

Output:
```text
> @autoerp/web@0.1.0 typecheck /Users/houssamr/Projects/syneriva/apps/erp/apps/web
> tsc --noEmit
(exit 0)
```

Hostile grep over `apps/web/src/features/products` found no new key-shape defects. All scoped callsites use `tenantScopedKey(...)` correctly. Relevant entries confirmed:

- `useProductRealtime.ts:84` — `queryKey: tenantScopedKey(['product', productId])`
- `useProductRealtime.ts:87` — `predicate: productsInvalidationPredicate(tenantId, companyId)`
- `useProducts.ts:64` — `queryKey: tenantScopedKey([...productKeys.list(params)])`
- `useProducts.ts:78` — `queryKey: tenantScopedKey([...productKeys.detail(id)])`
- Test seed at line 463-464: `const singularKey = ['product', 'prod-1', 'tenant-A', 'company-1']`
- Test assertion at line 499: `expect(singularAfter!.state.isInvalidated).toBe(true)`
