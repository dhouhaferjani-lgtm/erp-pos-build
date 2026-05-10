Commit reviewed: 8ad3d828
Verdict: REQUEST-CHANGES

## Round-1 Closure

[F1] closed at 8ad3d828. `apps/web/src/features/products/hooks/useProductRealtime.ts:67-70` subscribes to state values (`user`, `currentCompanyId`, `companies`) and derives `currentCompany` with `companies.find((c) => c.id === currentCompanyId) ?? null`, not via a stable `getCurrentCompany` action selector. `apps/web/src/features/products/hooks/useProductRealtime.ts:72-73` derives the closure values from the selected tenant/company, and `apps/web/src/features/products/hooks/useProductRealtime.ts:93` includes `tenantId` and `companyId` in the `handleUpdate` callback dependencies, so predicate invalidation is recreated after a company switch. The subscription gate at `apps/web/src/features/products/hooks/useProductRealtime.ts:102-104` uses the lookup-based `currentCompany`; it is truthy when the selected company is present and falsy when no selected company is found. The channel uses that same lookup at `apps/web/src/features/products/hooks/useProductRealtime.ts:108-112`.

[F2] not-closed at 8ad3d828. The upload-cascade part is closed: `apps/web/src/features/products/__tests__/tenantScope.test.tsx:12` imports `ProductImageUpload`, `apps/web/src/features/products/__tests__/tenantScope.test.tsx:394-398` renders `ProductImageSection` plus the real `ProductImageUpload`, and `apps/web/src/features/products/__tests__/tenantScope.test.tsx:411-415` drives the real input with `userEvent.upload(input, file)`. The realtime-cascade part is only partially closed. The test does mock `useRealtimeChannel` and capture `onEvent` in `realtimeRef` at `apps/web/src/features/products/__tests__/tenantScope.test.tsx:53-64`, renders `useProducts` plus `useProductRealtime` at `apps/web/src/features/products/__tests__/tenantScope.test.tsx:430-435`, and simulates the backend event at `apps/web/src/features/products/__tests__/tenantScope.test.tsx:452-461`. However, it only seeds/asserts the `useProducts` list refetch path: `apps/web/src/features/products/__tests__/tenantScope.test.tsx:432` is the sole product query hook in the probe, and `apps/web/src/features/products/__tests__/tenantScope.test.tsx:465-467` only expects `mockApiGet` to increase from 1 to 2. That protects the predicate invalidation at `apps/web/src/features/products/hooks/useProductRealtime.ts:86-88`, but it does not protect the first invalidate at `apps/web/src/features/products/hooks/useProductRealtime.ts:83-85` because the test never renders/seeds a matching singular `['product', productId]` cache entry. Removing that first invalidate would leave the observed `useProducts` refetch intact, so the closure claim that removing either invalidate makes the test fail is not satisfied.

## New Findings (if any)

None.

## Verification Run

`git rev-parse HEAD`

Output:
```text
c4023d55c8224f729de13b9670b2ed20cb8f90dc
```

`git diff --name-only 8ad3d828..HEAD -- apps/web/src/features/products/hooks/useProductRealtime.ts apps/web/src/features/products/__tests__/tenantScope.test.tsx`

Output:
```text
```

`rg -n "getCurrentCompany|currentCompany|currentCompanyId|shouldSubscribe|useRealtimeChannel|ProductImageUpload|userEvent\\.upload|RealtimeProbe|realtimeRef|handleUpdate" apps/web/src/features/products/hooks/useProductRealtime.ts apps/web/src/features/products/__tests__/tenantScope.test.tsx`

Output excerpt:
```text
apps/web/src/features/products/hooks/useProductRealtime.ts:68:  const currentCompanyId = useCompanyStore((s) => s.currentCompanyId)
apps/web/src/features/products/hooks/useProductRealtime.ts:70:  const currentCompany = companies.find((c) => c.id === currentCompanyId) ?? null
apps/web/src/features/products/hooks/useProductRealtime.ts:102:  const shouldSubscribe = Boolean(
apps/web/src/features/products/hooks/useProductRealtime.ts:103:    enabled && user && currentCompany && productId
apps/web/src/features/products/__tests__/tenantScope.test.tsx:12:import { ProductImageUpload } from '../components/ProductImageUpload'
apps/web/src/features/products/__tests__/tenantScope.test.tsx:58:const realtimeRef = { onEvent: null as ((data: unknown) => void) | null }
apps/web/src/features/products/__tests__/tenantScope.test.tsx:60:  useRealtimeChannel: ({ onEvent }: { onEvent: (data: unknown) => void }) => {
apps/web/src/features/products/__tests__/tenantScope.test.tsx:389:describe('ProductImageUpload mutation cascade (callsites .558 + .559)', () => {
apps/web/src/features/products/__tests__/tenantScope.test.tsx:425:describe('useProductRealtime production cascade through handleUpdate', () => {
```

`node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'`

Output:
```text
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 827
827
```

`pnpm test -- --reporter=verbose src/features/products/__tests__/tenantScope.test.tsx 2>&1 | tail -50` from `apps/web`

Output excerpt:
```text
✓ src/features/products/__tests__/tenantScope.test.tsx > ProductImageUpload mutation cascade (callsites .558 + .559) > uploading via the rendered ProductImageUpload component refetches the product-images query 76ms
✓ src/features/products/__tests__/tenantScope.test.tsx > useProductRealtime production cascade through handleUpdate > simulating a backend cost-price-updated event invalidates products + product-images caches 5ms

Test Files  1 passed (1)
     Tests  13 passed (13)
```

`pnpm test -- --reporter=verbose src/features/categories/hooks/__tests__/useCategories.tenantScope.test.tsx 2>&1 | tail -60` from `apps/web`

Output excerpt:
```text
Test Files  1 passed (1)
     Tests  9 passed (9)
```

`pnpm test -- --reporter=verbose src/lib/__tests__/tenantScopedKey.test.ts 2>&1 | tail -60` from `apps/web`

Output excerpt:
```text
Test Files  1 passed (1)
     Tests  9 passed (9)
```
