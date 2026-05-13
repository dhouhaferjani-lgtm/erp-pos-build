# web.tanstack-keys Batches 43-53 — Opus Review

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commits reviewed

| Batch | Fix commit | Scope | Callsites |
| --- | --- | --- | --- |
| B43 | b8b51f87 | workshop technician hooks | web.tanstack-keys.811-825 |
| B44 | 1538d2b2 | workshop work order hooks | web.tanstack-keys.826-838 |
| B45 | 94fe0000 | vehicle hooks | web.tanstack-keys.762-770 |
| B46 | becd85fa | withholding hooks | web.tanstack-keys.778-798 |
| B47 | 0e5e4a6c | treasury reconciliation hooks | web.tanstack-keys.718-734 |
| B48 | af17652e | batch hooks | web.tanstack-keys.030-044 |
| B49 | cd730fd9 | smart payment hooks | web.tanstack-keys.735-739 |
| B50 | c0448856 | additional cost hooks | web.tanstack-keys.174-181 |
| B51 | f5307e54 | credit note hooks | web.tanstack-keys.186-190 |
| B52 | d1181b91 | return note hooks | web.tanstack-keys.198-203 |
| B53 | 005bd90c | delivery note hooks | web.tanstack-keys.191-196 |

Scanner: 421 → 301 (-120 violations). Verify-history: clean at every step.

## Verdict

Verdict: APPROVE

APPROVE — all 11 batches.

## Gates evaluated (all pass per batch)

1. State-value tenant/company selectors used (`useAuthStore((s) => s.user?.tenant_id ?? null)`, `useCompanyStore((s) => s.currentCompanyId ?? null)`).
2. Read-hook `enabled:` AND-combines `!!tenantId && !!companyId` with each hook's pre-existing condition (`!!id`, debounced query, etc.).
3. All read `queryKey` arrays wrap in `tenantScopedKey([...factoryKey])`.
4. Mutation invalidations: predicate (`scopedNamespacePredicate`, namespace-specific exported predicate) for suffix-scoped factory keys (lists/filters); exact `tenantScopedKey([...])` for detail keys.
5. Cross-namespace cascades use awaited `Promise.all([...])`.
6. Per-call counter tests (per-(tenant,company) counters) prove intended-tenant refetch + absence of overfire across the inactive tenant.
7. Tenant-B cache markers persist through active-tenant mutations.
8. Tests exercise the production hooks via `renderHook`, not predicate-only stand-ins (B49 cross-namespace consumers use probe reads, which is appropriate given the namespaces are wrapped in other batches).

## Per-batch notes

- **B43** (technicians): Certifications use exact detail invalidation (correct — id-scoped namespace, no collision risk). Time-off / time-entries use `technicianAuthoringPredicate` that pins technicianId + segment + suffix. 9 mutations tested with 3 tenant-B markers preserved.
- **B44** (work orders): list vs detail counters advance independently after each mutation.
- **B45** (vehicles): Transfer mutation correctly pins partner-vehicles via predicate filtered on `new_owner_partner_id`; exact keys for vehicle and ownerships.
- **B46** (withholding): plural namespaces use predicate; singular `withholding-certificate` / `withholding-rule` use exact. Shared `sales-withholding-tracking` namespace correctly invalidates both list+detail (pre-existing semantic preserved).
- **B47** (treasury reconciliation): `useCompleteReconciliation` cascades 5 namespaces under one awaited Promise.all.
- **B48** (batches): `scopedBatchCollectionsPredicate` excludes `k[1] === 'detail'` from the collections sweep — detail handled by explicit exact invalidation. Cross-namespace `['products', 'detail', productId]` invalidated on create/update only (delete does NOT bump productDetailCalls — test asserts this).
- **B49** (smart payment): allocation invalidates `payments` (predicate), exact `payment`, exact `invoice` per allocation (mapped through Promise.all), `partner-balance` (predicate). Matches spec.
- **B50** (additional costs): create/update/delete all invalidate exact `['additional-costs', documentId]`, exact `['document', 'purchase_order', documentId]`, AND exact `['landed-cost-breakdown', documentId]`. Matches spec.
- **B51** (credit notes): create cascades `credit-notes` predicate + exact source `invoice` + `documents` predicate.
- **B52** (return notes): create cascades `return-notes` predicate + conditional exact source `invoice` + conditional exact source `delivery-note` + `documents` predicate. Fixture sets both source IDs so both branches fire.
- **B53** (delivery notes): consolidate cascades `delivery-notes` predicate (matches both plain list and `['delivery-notes', 'invoiceable', partnerId]`), `documents` predicate, `invoices` predicate.

## Non-blocking findings (out of scope for this sweep)

1. `useTransferVehicleOwnership.ts` / `useLogVehicleMileage.ts` invalidate `['vehicle', id, ...]` but do not invalidate `['vehicle-with-owner', id, ...]` (different first segment). Pre-existing — left to a follow-up.
2. `useConsolidateDeliveryNotes` does not invalidate the singular `['delivery-note', id]` detail namespace. Pre-existing.
3. `useSalesWithholdingTrackingRecord` shares the plural namespace `'sales-withholding-tracking'` with the list, making precise detail-only invalidation impossible. Pre-existing architectural smell.
4. Several mutation hooks call `useAuthStore(...)` / `useCompanyStore(...)` and discard the return value. This is the documented pattern from `apps/web/src/lib/tenantScopedKey.ts:11-18` — the subscription ensures re-render on tenant change while `tenantScopedKey()` itself reads via `getState()` at invocation. Consistent across batches; readability nit only.

## Locks applied

All callsite locks pinned to the corresponding fix commit shown in the table above.
