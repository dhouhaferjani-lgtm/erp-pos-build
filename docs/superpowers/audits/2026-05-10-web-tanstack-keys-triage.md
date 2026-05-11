# web.tanstack-keys triage — 2026-05-10

**Cluster status at start:** `pending`, 849 callsites seeded by `TanstackKeysScanner` at commit `9bb4643e`, foundation chain Codex round-2 APPROVE'd at `de2a3d24`. All 849 are `status: pending`, `owner: null`. Cluster `required_owner: codex`; per master plan §16.1 claude takes execution with `--force --reason --approved-by`; codex stays reviewer-only.

**Goal:** Carve 849 callsites into reviewable batches and start the per-batch fix-cycle workstream.

---

## Pattern-type distribution at seed

| count | pattern_type |
|------:|--------------|
|   274 | querykey_invalidatequeries_array_literal |
|   224 | querykey_usequery_array_literal |
|   140 | querykey_invalidatequeries_call_expression |
|   107 | querykey_usequery_call_expression |
|    65 | querykey_invalidatequeries_other |
|    29 | querykey_invalidatequeries_identifier |
|     3 | querykey_useinfinitequery_call_expression |
|     3 | querykey_usequery_other |
|     2 | querykey_usequeries_queries__call_expression |
|     1 | querykey_removequeries_array_literal |
|     1 | querykey_usequery_identifier |

Two largest buckets (498 of 849 = 59%) are array_literal — easiest fix shape (`tenantScopedKey([...existing])`). The call_expression buckets (247) cover queryKey factories like `categoryKeys.list(params)`; the long tail (`other`/`identifier`) is bare-identifier or member-access (e.g., `categoryKeys.all`).

---

## Per-feature distribution (top 30)

| count | feature root |
|------:|--------------|
|   109 | features/documents |
|    86 | features/pos |
|    70 | features/treasury |
|    50 | features/catalog |
|    45 | features/settings |
|    38 | features/loyalty |
|    21 | features/scheduling |
|    21 | features/withholding |
|    20 | features/parapharmacy |
|    19 | features/inventory |
|    19 | features/opening-balances |
|    17 | components/organisms |
|    17 | features/finance |
|    17 | features/inventory-counting |
|    16 | features/parts-catalog |
|    16 | features/vehicles |
|    15 | features/batches |
|    15 | features/partners |
|    15 | features/workshop-technicians |
|    14 | features/expenses |
|    14 | features/import |
|    13 | features/services |
|    13 | features/workshop-work-orders |
|    12 | features/compliance |
|    12 | features/menu |
|    12 | features/products |
|    12 | features/workshop-bundles |
|    11 | pages/POS |
|    10 | features/categories |
|     9 | features/crm |
|     9 | features/pricing |

(Long tail: features/promotions 8, features/uom 8, features/progression 8, features/coupons 7, features/vouchers 7, features/enrichment 5, features/purchases 5, features/reports 5, features/vat-reporting 5, features/dashboard 4, features/auth 3, features/company 3, features/locations 2, features/users 2, features/customer-history-audit 1, contexts/CompanyConfigContext.tsx 1.)

---

## Fix template

The audit-tanstack-keys scanner approves a queryKey expression iff:

1. The expression IS a CallExpression `tenantScopedKey(...)` (callee must be a bare Identifier, NOT a property-access).
2. OR the expression IS an ArrayLiteral whose elements include an approved scope (bare identifier `tenantId`/`currentCompanyId`/`companyId`, or member access `companyStore.<scope>`, or first element is namespace literal `'admin'`/`'super-admin'`).

**Implication for queryKey factories** (e.g., `categoryKeys.list(params)`): wrapping inside the factory does NOT satisfy the scanner because `categoryKeys.list(params)` is a CallExpression on a PropertyAccess, not on a bare Identifier. Therefore each callsite MUST inline the wrap:

```ts
// BEFORE (flagged):
queryClient.invalidateQueries({ queryKey: categoryKeys.lists() })

// AFTER (approved):
queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.lists()]) })
```

For bare array literals (the 498 largest buckets):

```ts
// BEFORE:
useQuery({ queryKey: ['payment-methods', filter], ... })

// AFTER:
useQuery({ queryKey: tenantScopedKey(['payment-methods', filter]), ... })
```

For property-access invalidation tags (e.g., `categoryKeys.all`):

```ts
// BEFORE:
queryClient.invalidateQueries({ queryKey: categoryKeys.all })

// AFTER:
queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.all]) })
```

**Causal-recomputation gate** (per Codex round-1 advisory note on the foundation review): callsites that depend on auth/company state for re-render-on-change must subscribe to those stores in the hook body, not just rely on `tenantScopedKey()`'s `getState()` snapshot. Pattern:

```ts
export function useCategories(params?: ...): UseQueryResult<...> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.list(params)]),
    queryFn: () => fetchCategories(params),
    enabled: !!tenantId && !!companyId,
    staleTime: 60000,
  })
}
```

The two store reads serve double duty: (a) they re-render the hook on tenant/company switch so `tenantScopedKey()` reads fresh `getState()` values, and (b) they feed the `enabled` guard that suppresses fetches before auth + company are populated.

---

## Batch table

Batch sizes target ~10 callsites in a single feature directory whenever possible, to keep diffs reviewable and a single PR per batch ergonomic. The first three batches are concrete; later batches become rough estimates that may be re-triaged after each lock.

| batch_id | scope | callsites | callsite range | pattern mix | notes |
|----------|-------|-----------|----------------|-------------|-------|
| **B1** | `features/categories/hooks/useCategories.ts` | 10 | `web.tanstack-keys.095`–`.104` | 3 useQuery_call_expression + 5 invalidate_call_expression + 2 invalidate_other | Single-file; tight factory pattern (`categoryKeys.{all,lists,list,trees,tree,details,detail}`); validates the helper end-to-end across all factory shapes |
| B2 | `features/products/components/Product*.tsx` + `hooks/useProducts.ts` + `hooks/useProductRealtime.ts` | 12 | TBD | 8 invalidate_array_literal + 2 useQuery_array_literal + 2 useQuery_call_expression | Mixed array_literal + factory; multi-file batch tests cross-file consistency |
| B3 | `pages/POS/POSTransactions.tsx` + `pages/POS/POSShiftsDashboard.tsx` + `pages/POS/Terminals.tsx` | 11 | TBD | 6 useQuery_array_literal + 5 invalidate_array_literal | POS pages — bare array_literals, no factories |
| B4 | `features/uom/hooks/useUnits.ts` | 8 | TBD | 6 invalidate_call_expression + 2 useQuery_call_expression | Compact factory-only file |
| B5 | `features/promotions/hooks/usePromotions.ts` | 8 | TBD | 6 invalidate_identifier + 2 useQuery_array_literal | Identifier-shape (rare); validates wrap of bare-identifier queryKeys |
| B6 | `features/progression/hooks/*` | 8 | TBD | 4 useQuery_call_expression + 4 invalidate_call_expression | 3 files in same dir |
| B7+ | Remaining 792 callsites across 35+ feature directories | 792 | TBD | mixed | Rough estimate ~80 batches of ~10. Re-triage between sessions; pattern stabilizes after B1-B3 |

**Why B1 is the right starter:** single file, exercises every shape category the scanner cares about (3 useQuery + 5 call-expression invalidate + 2 property-access "other" invalidate), 10 callsites is at the helper's validation sweet spot, and the categoryKeys factory is canonical (lots of features have `<x>Keys` factories). Lessons here will inform the wrap-vs-rewrite decision for every remaining factory file.

---

## Per-batch cadence (locked from kickoff brief)

1. Claim each callsite with `php artisan sweep:inventory:claim --callsite-id <id> --actor=claude --force --reason "ownership reassigned 2026-05-09 per architectural-surface-closed recalibration; codex remains reviewer-only" --approved-by admin@otospex.com`.
2. Start each callsite with `php artisan sweep:inventory:start --callsite-id <id> --actor=claude`.
3. Write a Vitest red anchor pinning the cross-tenant cache-leak shape (snapshot queryKey before tenant switch, assert it changes after).
4. Apply mechanical fix per the Fix template above.
5. Quality gates: `pnpm vitest run <batch tests>`, `pnpm typecheck`, `node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'` (must drop by N), `php artisan sweep:inventory:verify-history` (must report 0 problems).
6. `php artisan sweep:inventory:submit --callsite-id <id> --actor=claude --commit-sha <fix-sha>` per callsite.
7. Dispatch Codex review at the fix commit SHA. Iterate rounds until APPROVE or APPROVE-WITH-MINOR-EDITS-APPLIED.
8. Lock: `php artisan sweep:inventory:review --callsite-id <id> --reviewer=codex --verdict <V> --review-file <path>` per callsite. Cluster auto-rolls each batch's callsites to `fixed`. Cluster status stays `pending` until 849/849.

---

## Stop conditions

- **STOP-1** Context budget < 30% — lock current batch cleanly + brief.
- **STOP-2** Codex BLOCKER requiring spec decision — surface, don't improvise.
- **STOP-3** verify-history reports a problem mid-session — diagnose root cause; orphan-style → revert+redo.
- **STOP-4** Two consecutive batches surface the same Codex finding shape → architectural pattern question, not per-batch work.
- **STOP-5** Batch 1 exposes a runtime cache-invalidation surprise (helper's `getState()` doesn't actually invalidate as expected during tenant-switch test) → STOP and surface; helper contract may need revision.

---

## Reference files (dependencies, not modified per batch)

- `apps/web/src/lib/tenantScopedKey.ts` — the helper.
- `apps/web/tools/audit-tanstack-keys.mjs` — the scanner / approval source of truth.
- `apps/web/src/lib/__tests__/tenantScopedKey.test.ts` — helper unit tests (9 cases, includes tenant-switch invariant).
- `apps/web/src/test/renderWithProviders.tsx` — RTL+QueryClient wrapper.
- `apps/web/src/stores/authStore.ts` — `useAuthStore` (zustand+persist; `user.tenant_id` is the tenant scope).
- `apps/web/src/stores/companyStore.ts` — `useCompanyStore` (`currentCompanyId` is the company scope).
