# web.tanstack-keys Batch 68 — Opus Review

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `54b7e9a0`
Scope: `apps/web/src/features/enrichment/api/enrichmentQueries.ts` — 5 callsites (web.tanstack-keys.250-254).
Test: `apps/web/src/features/enrichment/api/__tests__/enrichmentQueries.tenantScope.test.tsx`

Scanner: 228 → 223 (-5 violations). Verify-history: clean.

## Verdict

APPROVE.

## Gates evaluated

1. **Key wrap**: `tenantScopedKey([...enrichmentKeys.list(params)])` and `tenantScopedKey([...enrichmentKeys.detail(id)])`. Exported `enrichmentInvalidationPredicate` correctly filters on `k[0]==='enrichment-results'` and suffix-checks `k[k.length-2]===tenantId && k[k.length-1]===companyId`, covering both list and detail under one predicate.
2. **Gating**: state-value selectors on every hook. `useEnrichmentResult` combines `id.length > 0` with tenant/company nullness; reads gated correctly.
3. **Mutations**: `useAcceptEnrichment` awaits invalidation; per-call counters `listCalls`/`detailCalls` advance 1→2 after `mutateAsync`, proving the predicate hits both shapes. No overfire on the inactive tenant.
4. **Tenant-B isolation**: marker `['enrichment-results', 'list', undefined, 'tenant-B', 'company-1']` preserved through the active-tenant mutation.
5. **Production hooks used**: `useEnrichmentResults`, `useEnrichmentResult`, `useAcceptEnrichment` exercised via `renderHook`.

## Non-blocking findings

1. Test comments (lines 79, 93) label callsites as `.347-.348` / `.349`; actual inventory IDs are `.250-.254`. Cosmetic-only.
2. Only `useAcceptEnrichment` is mutation-tested; `useRejectEnrichment` and `useBulkAcceptEnrichment` share `enrichmentInvalidationPredicate` so the contract is proven once, but a direct assertion would catch accidental cascade addition/removal on the other two hooks. Coverage gap, not a defect.

## Locks applied

5 callsites locked at fix commit `54b7e9a0`.
