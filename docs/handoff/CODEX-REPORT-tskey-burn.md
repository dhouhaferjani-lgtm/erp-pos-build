# CODEX Report: TanStack Tenant-Key Baseline Burn

Date: 2026-07-02
Branch: fix/tenant-key-baseline-burn

## Changes

- `apps/web/tools/audit-tanstack-keys.mjs`
  - Emptied `BASELINED_VIOLATION_KEYS`.
  - Audit now reports 0 acknowledged, 0 new, 0 stale baseline entries.

- `apps/web/src/features/purchases/supplier-invoices/api.ts`
  - Reworked `supplierInvoiceKeys` to return unscoped structural key segments.
  - Wrapped read call sites with direct `tenantScopedKey([...supplierInvoiceKeys.*(...)])` so the audit can verify suffix tenant/company scope.
  - Kept list/detail/attachments reads gated on both `tenantId` and `companyId`.
  - Replaced supplier invoice list/detail/attachment invalidations with predicates that match resource prefix plus tenant/company suffix.
  - Updated `setQueryData` for posted detail updates to write to the tenant-scoped detail key.

- `apps/web/src/features/inventory/useLoyaltyEarnRate.ts`
  - Changed `['loyalty', 'earn-rate']` to `tenantScopedKey(['loyalty', 'earn-rate'])`.
  - Added store subscriptions and gated reads on `tenantId` plus `companyId`, preserving the caller-provided `enabled` flag.

- `apps/web/src/features/catalog/hooks/useVariants.ts`
  - Replaced attribute and product-variant invalidations with tenant/company suffix predicates.
  - Mutation hooks now subscribe to active tenant/company scope before invalidating.

## Tests Added/Extended

- Added `apps/web/src/features/purchases/supplier-invoices/api.tenantScope.test.tsx`
  - Covers list/detail/attachment read key suffixes and missing-scope read gating.
  - Covers create/rematch/post/upload/delete/payment invalidation paths against active-tenant caches.

- Extended `apps/web/src/features/catalog/hooks/__tests__/tenantScope.test.tsx`
  - Covers product attribute and product variant read key suffixes and missing-scope read gating.
  - Covers attribute and variant mutation cascades with active-tenant refetches and sibling tenant cache preservation.

- Extended `apps/web/src/features/inventory/__tests__/tenantScope.test.tsx`
  - Covers loyalty earn-rate tenant/company suffix key and missing-scope read gating.

## Verification

- `node tools/audit-tanstack-keys.mjs`
  - Exit 0.
  - Output: `Gate C ...: 0`
  - Output: `Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries`

- `npx vitest run src/features/purchases/supplier-invoices/api.tenantScope.test.tsx src/features/catalog/hooks/__tests__/tenantScope.test.tsx src/features/inventory/__tests__/tenantScope.test.tsx`
  - Exit 0.
  - 3 files passed, 15 tests passed.
  - Existing React `act(...)` warnings still print from these suites.

- `npx tsc --noEmit`
  - Exit 0.

## Semantic Risks

- Supplier invoice and catalog mutation hooks now capture `tenantId`/`companyId` via store subscriptions. That is intentional for causal rerenders with `tenantScopedKey()`, but mutation invalidation scope is the scope at hook render time.
- Supplier invoice post still writes the returned detail into cache immediately, now under the active tenant-scoped detail key; if a mutation were somehow triggered after a scope switch without a rerender, it would use the prior hook scope.
- Catalog attribute invalidation intentionally covers both `catalogAttributes.list` and `catalogAttributes.values` for the active tenant/company, matching the prior broad `attributeKeys.all` behavior but without cross-scope matching.
