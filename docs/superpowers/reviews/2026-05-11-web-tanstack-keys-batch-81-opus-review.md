# web.tanstack-keys Batch 81 — Opus Review

Commit reviewed: c9b204f0
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `c9b204f0` — fix(tenant-isolation): wrap web.tanstack-keys batch 81 (vehicle form)
Scope: 5 callsites in `apps/web/src/features/vehicles/VehicleForm.tsx`.
- .756 read partners, .757 read vehicle (edit)
- .758 invalidate vehicles predicate (create), .759 invalidate vehicles predicate (update), .760 invalidate vehicle exact (update)

Test: `VehicleForm.tenantScope.test.tsx` (new, 210 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: Both reads use `tenantScopedKey([...])`. Create invalidate uses `scopedNamespacePredicate('vehicles', ...)`. Update invalidate uses `Promise.all([predicate, exact-key])`.
2. **State-value selectors**: Selects `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: Both reads AND-combine `tenantId !== null && companyId !== null` with existing `isEdit`.
4. **Async invalidate**: Both create/update onSuccess handlers `async/await`; update uses `Promise.all([…])` for parallel invalidate.

## Non-blocking findings

1. `scopedNamespacePredicate` inlined again. Tracked.

## Locks applied

5 callsites locked at fix commit `c9b204f0`:
web.tanstack-keys.756, .757, .758, .759, .760.
