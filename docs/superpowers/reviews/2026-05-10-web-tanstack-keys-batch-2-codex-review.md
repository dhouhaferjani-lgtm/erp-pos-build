Commit reviewed: bbba88ff
Verdict: REQUEST-CHANGES

## Findings

[F1] Stale company capture in `useProductRealtime` — `apps/web/src/features/products/hooks/useProductRealtime.ts:63`. The hook subscribes only to `getCurrentCompany` (the stable action selector returned by `useCompanyStore((state) => state.getCurrentCompany)`), so company switches do not force a re-render. The `companyId` captured by `currentCompany?.id ?? null` is stale after a company switch, and the predicate-based invalidate at line ~80 (`predicate: productsInvalidationPredicate(tenantId, companyId)`) targets the *old* company's tenant-scoped cache slot rather than the new one. Cross-tenant isolation degrades to "stale-after-switch" until the next mount.

[F2] Test-honesty gap — `apps/web/src/features/products/__tests__/tenantScope.test.tsx:389` (upload-cascade) and `apps/web/src/features/products/__tests__/tenantScope.test.tsx:202` (predicate). The upload-cascade test uses a duplicated `UploadHookProbe` rather than rendering the production `ProductImageUpload.tsx:32` mutation; removing `ProductImageUpload.tsx`'s `onSuccess` invalidate would NOT fail the test. The realtime tests call `productsInvalidationPredicate` directly rather than driving the production `handleUpdate` callback at `useProductRealtime.ts:80`; removing the predicate invocation in the production handler would NOT fail any test in this file.

## Notes

For round 2 against the next fix commit, verify:
1. `useProductRealtime` subscribes to `currentCompanyId` (a state value) so the closure recomputes on company switch — not to the action selector.
2. The upload test renders the production `ProductImageUpload` and exercises its onSuccess via `userEvent.upload` (or equivalent path that drives React's synthetic event system).
3. The realtime test simulates a backend event via the mocked `useRealtimeChannel`'s captured `onEvent` callback, so production `handleUpdate` runs and removing either invalidate at `useProductRealtime.ts` causes a test to fail.

## Verification Run

```text
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
  → 827 (down from 839; delta = 12, exact batch size; no remaining
    features/products violations)

git diff 24fca0a6..bbba88ff --stat
  → scoped to product feature files + new test file + inventory YAML

pnpm typecheck
  → passed

pnpm vitest run src/features/products/__tests__/tenantScope.test.tsx
  → blocked by sandbox EPERM (Vite temp-dir write) — sandbox constraint,
    not a test failure. Main session ran this independently and reported
    13/13 pass.
```
