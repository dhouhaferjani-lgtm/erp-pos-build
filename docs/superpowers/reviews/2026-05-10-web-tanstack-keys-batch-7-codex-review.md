Commit reviewed: 052e763e

## Summary
Batch 7 wraps 7 callsites across the vouchers feature namespace: list/detail query factories in `useVouchers.ts` export a shared `VOUCHERS_KEY` constant and a `vouchersInvalidationPredicate`; `useVoucherMutations.ts` imports the predicate for all four mutation invalidations; `useReservationSettings.ts` is rewritten to use explicit state-value selectors and `tenantScopedKey` in place of the manual `currentCompany?.id` queryKey segment. The sibling-namespace isolation pattern is exercised by a new cascade test.

## Axis Reviews

### 1. Tenant-scope correctness
`useVouchers.ts:37` and `:47` both use `tenantScopedKey([...VOUCHERS_KEY, ...])` — tenant and company IDs are appended by the helper, replacing all bare `[namespace, id]` patterns. `useReservationSettings.ts:12` uses `tenantScopedKey(['reservation-settings'])` in place of the previous `['reservation-settings', currentCompany?.id]`. Semantically equivalent: `tenantScopedKey` appends `[tenantId, companyId]` exactly as the manual pattern did, plus now picks up tenantId which the old form omitted. PASS.

### 2. Key factory shape
`VOUCHERS_KEY` at `useVouchers.ts:8` is defined as a readonly tuple `['vouchers']`. List and detail factories extend it with `[...VOUCHERS_KEY, params]` and `[...VOUCHERS_KEY, 'detail', id]` respectively — spread-based factory shape, consistent with the sweep pattern. PASS.

### 3. Predicate precision
`vouchersInvalidationPredicate` at `useVouchers.ts:17–30` gates on `k[0] === 'vouchers'` plus a tenant/company suffix match. It will NOT match `['reservation-settings', ...]` keys because the first segment is a different string. All four mutations in `useVoucherMutations.ts` (goodwill `:23`, void `:42`, transfer `:61`, extend `:80`) use this predicate exclusively. PASS.

### 4. Cascade test adequacy
`tenantScope.test.tsx:129` asserts `vouchersInvalidationPredicate` returns `false` for a reservation-settings key. `tenantScope.test.tsx:278` and `:295` assert the reservation-settings query counter stays at 1 after goodwill and void mutations respectively. Transfer and extend mutations use the same predicate shape (`:62`, `:81`) so coverage is transitive. PASS.

### 5. Cross-file contract (VOUCHERS_KEY)
`useVouchers.ts` exports both `VOUCHERS_KEY` and `vouchersInvalidationPredicate`. `useVoucherMutations.ts:8` imports `vouchersInvalidationPredicate` (not `VOUCHERS_KEY` directly) — this is intentional and correct: the predicate encapsulates the key shape so mutations never need to reconstruct it. The shared-identifier contract is sound. PASS.

### 6. No scope creep
Changes are confined to the three listed hook files and the single test file. No unrelated modules, stores, or utility files were modified. PASS.

## Findings
None.

## Verdict Rationale
All six axes pass. The `tenantScopedKey` substitution in `useReservationSettings.ts` is a strict improvement (now also scopes by tenantId). The predicate correctly isolates the vouchers namespace, and the cascade test proves sibling-namespace non-contamination at runtime. No blockers or regressions identified.

Verdict: APPROVE
