# web.tanstack-keys Batch 90 — Opus Review

Commit reviewed: 0dbef42c
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `0dbef42c` — fix(tenant-isolation): wrap web.tanstack-keys batch 90
Scope: 10 callsites across 5 production files (shared selectors).
- `apps/web/src/components/organisms/AddVehicleModal/AddVehicleModal.tsx` (.004 invalidate vehicles via predicate, .005 invalidate partner exact key)
- `apps/web/src/components/organisms/LocationSelector/LocationSelector.tsx` (.006 invalidate stock-levels via predicate, .007 invalidate stock-movements via predicate)
- `apps/web/src/components/ui/LocationSelector.tsx` (.020 read locations, .021 read location)
- `apps/web/src/components/ui/PartnerSearchSelect.tsx` (.022 read partners-search, .023 read partner)
- `apps/web/src/components/ui/ProductSearchSelect.tsx` (.024 read products-search, .025 read product)

Test: `apps/web/src/components/__tests__/SharedSelectors.tenantScope.test.tsx` (new, 242 lines, 4 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap + predicate**: All 6 read queries use `tenantScopedKey([...])`. Invalidate sites split into two patterns: exact-key (`tenantScopedKey(['partner', partnerId])`) and namespace-scoped predicate (`scopedNamespacePredicate('vehicles', tenantId, companyId)` and the two `stock-*` predicates). The predicate path correctly invalidates all variants of a namespace (e.g., `['vehicles']`, `['vehicles', 'list']`, `['vehicles', filter]`) within the active tenant — required because consumer queries hold dynamic key shapes.
2. **State-value selectors**: Every consumer selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`.
3. **Enabled gates**: All 6 read queries AND-combine `tenantId !== null && companyId !== null` with their existing `isOpen`/`Boolean(value)` predicates.
4. **Async invalidate cascade**: `AddVehicleModal.onSuccess` converted to `async` and awaits both invalidate calls. `LocationSelector.handleLocationChange` (header bar) retains `void` because the location switch is a fire-and-forget UI action and the menu close should not block on refetch — acceptable.
5. **Cross-tenant isolation test**: Four it-blocks. (a) AddVehicleModal: seeds tenant-A AND tenant-B vehicles cache, drives save, asserts tenant-A vehicles + tenant-A partner flip to `isInvalidated=true` while tenant-B vehicles stays `isInvalidated=false`. (b) Header location switch: seeds tenant-A AND tenant-B stock-levels, drives switch, asserts tenant-A stock-levels + stock-movements invalidated, tenant-B NOT. (c) 6 reads asserted at `[..., 'tenant-A', 'company-1']`. (d) Tenantless gating produces no API calls.

## Non-blocking findings

1. **Inlined predicate helper**: `scopedNamespacePredicate` is defined locally in both `AddVehicleModal.tsx` and `LocationSelector/LocationSelector.tsx` with identical bodies. A shared utility at `apps/web/src/lib/tenantScopedKey.ts` (e.g., `tenantScopedPredicate(namespace)`) would be cleaner and prevent drift. Out of scope for this batch but worth a tracked follow-up.
2. **Predicate length check** (`k.length >= 3`) is the minimum that supports `[namespace, tenantId, companyId]`. It correctly rejects orphan `[namespace]` legacy entries (which shouldn't exist post-sweep but defensively skipped). Correct.
3. The `LocationSelector` header-bar invalidations use the predicate path; the read sites (`tenantScopedKey(['stock-levels', ...])` callsites in pages — not this batch) embed tenant/company in the suffix automatically, so the predicate matches them. Verified by the seeded `['stock-levels', 'tenant-A', 'company-1']` assertion.
4. AddVehicleModal's partner invalidate uses an exact key (`tenantScopedKey(['partner', partnerId])`) rather than a predicate — appropriate because the partner detail view has a fixed key shape and an exact invalidate is more precise than a namespace sweep.

## Locks applied

10 callsites locked at fix commit `0dbef42c`:
web.tanstack-keys.004, .005, .006, .007, .020, .021, .022, .023, .024, .025.
