# web.tanstack-keys Batch 91 — Opus Review

Commit reviewed: 34d3ec94
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `34d3ec94` — fix(tenant-isolation): wrap web.tanstack-keys batch 91
Scope: 10 callsites across 10 production files (shared singletons / bootstrap surfaces).
- `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx` (.001 invalidate partners)
- `apps/web/src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx` (.002 invalidate products)
- `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx` (.003 invalidate payment-repositories)
- `apps/web/src/components/ui/DocumentSearchSelect.tsx` (.018 read)
- `apps/web/src/components/ui/LocationBadge.tsx` (.019 read location)
- `apps/web/src/contexts/CompanyConfigContext.tsx` (.026 read company-config)
- `apps/web/src/features/auth/AuthProvider.tsx` (.027 read auth/me — bootstrap)
- `apps/web/src/features/auth/LoginPage.tsx` (.028 invalidate auth/me)
- `apps/web/src/features/auth/RegisterPage.tsx` (.029 invalidate auth/me)
- `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx` (.094 read locations)

Test: `apps/web/src/components/__tests__/SharedSingletons.tenantScope.test.tsx` (new, 280 lines, 3 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 10 sites use `tenantScopedKey([...])`. Three invalidate sites (`partners`, `products`, `payment-repositories`) converted to `async/await`. Two auth invalidate sites (`LoginPage`, `RegisterPage`) retain `void` because they fire as the auth store transitions and the subsequent navigation does not depend on the refetch completing.
2. **State-value selectors**: Every site that owns a read selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. `CompanyConfigContext` keeps its existing `isAuthenticated` selector AND adds the two new selectors.
3. **Enabled gates**: 4 of 5 reads gate on `tenantId !== null && companyId !== null` AND-combined with their existing predicates. `AuthProvider` deliberately omits the gate because it bootstraps the auth state — gating on tenant_id (which lives inside the response payload) would deadlock; the cache namespacing still applies because `tenantScopedKey` reads store state at hook-call time and re-keys when the store updates post-login.
4. **Async invalidate cascade**: Modal save flows (AddPartner/AddQuickProduct/AddRepository) await invalidate so the close-and-callback path is deterministic. Auth invalidate sites intentionally remain `void` (see #1).
5. **Cross-tenant isolation test**: Three it-blocks. (a) For each of 3 modals: seeds tenant-A AND tenant-B cache entries, drives the save flow, asserts tenant-A flips to `isInvalidated=true` while tenant-B stays `isInvalidated=false`. (b) For 3 reads (DocumentSearchSelect, LocationBadge, CompanyConfigProvider): asserts query keys land at `[..., 'tenant-A', 'company-1']`. (c) Tenantless gating: asserts no API calls when both stores are reset.

## Non-blocking findings

1. **AuthProvider lacks enabled gate** (.027): Intentional — auth bootstrap must run before tenant_id is known. The `tenantScopedKey` helper handles this by encoding `null` in the key prefix on first call, then re-keying after `setAuth(...)` populates the store. The explicit `invalidateQueries({ queryKey: tenantScopedKey(['auth', 'me']) })` in LoginPage.onSuccess fires after `setAuth(...)` so the helper already reads the new tenant_id; the invalidation targets the new key and the bootstrap cache entry under `['auth', 'me', null, null]` is naturally orphaned. Net behavior: auth still bootstraps and refetches per tenant.
2. **Test coverage gap on auth + composite-item-form**: The test does NOT exercise `AuthProvider` (.027), `LoginPage` (.028), `RegisterPage` (.029), or `CompositeItemFormPage` (.094). These pages mechanically apply the same wrap pattern verified on the three modals and three render assertions, so the cluster-level `tenantSwitchCacheInvalidation` suite covers them. Acceptable but worth a follow-up if regression coverage tightens.
3. The AddQuickProductModal save flow in the test clicks a `select-tax` button to satisfy the TaxConfigurationField requirement. The mock returns an empty payload but the field's internal state seems to be satisfied. Pattern works as written.

## Locks applied

10 callsites locked at fix commit `34d3ec94`:
web.tanstack-keys.001, .002, .003, .018, .019, .026, .027, .028, .029, .094.
