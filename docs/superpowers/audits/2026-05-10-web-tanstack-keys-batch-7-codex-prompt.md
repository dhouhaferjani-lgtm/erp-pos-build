# Codex review prompt — web.tanstack-keys batch 7 (vouchers hooks)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Fix commit:** `052e763e`
**Files under review (3):**
- `apps/web/src/features/vouchers/hooks/useVouchers.ts`
- `apps/web/src/features/vouchers/hooks/useVoucherMutations.ts`
- `apps/web/src/features/vouchers/hooks/useReservationSettings.ts`

**Test:** `apps/web/src/features/vouchers/__tests__/tenantScope.test.tsx`
**Callsites:** `web.tanstack-keys.771`, `.772`–`.775`, `.776`, `.777` (7)
**Cluster:** `web.tanstack-keys` (was 793; this batch closes 7 → 786 remaining if APPROVE)

## Context (compressed — first 3-file batch in the streak)

Same pattern as B5/B6 with two new wrinkles:
1. **Cross-file shared identifier**: `VOUCHERS_KEY = ['vouchers']` is exported from `useVouchers.ts` and imported by `useVoucherMutations.ts`. The predicate `vouchersInvalidationPredicate` is also exported from `useVouchers.ts` so the mutations file imports it.
2. **Sibling namespace `reservation-settings`**: `useReservationSettings.ts` is in the same vouchers feature dir but uses a different namespace. Voucher mutations must NOT invalidate this hook's cache.

The `useReservationSettings.ts` rewrite specifically replaces the previous `const { currentCompany } = useCompany()` destructure with explicit state-value selectors — same lesson as B2 round-1 F1 (action-selector subscription doesn't trigger re-render on state change), applied preventatively to a destructure that was the same antipattern. The queryKey was previously `['reservation-settings', currentCompany?.id]` (manual companyId append); the rewrite uses `tenantScopedKey(['reservation-settings'])`, letting the helper handle t/c suffixing.

The mutations test mock targets the `voucherApi` module (not `@/lib/api`) because `listVouchers` calls `api.get` directly to preserve pagination meta — mocking at the module-function boundary is simpler than stubbing the axios client.

## What to verify

1. Scanner delta = 7 (`audit-tanstack-keys.mjs` count drops from 793 to 786). **Confirmed live: 786.**
2. State-value selectors used everywhere — including the `useReservationSettings.ts` rewrite (no more `useCompany()` destructure).
3. Predicate `vouchersInvalidationPredicate` matches `[vouchers, params|id, t, c]` and **rejects** `['reservation-settings', t, c]` (asserted in the test's negative cases).
4. Cascade tests assert sibling-namespace invariant — voucher mutations advance list/detail counters but leave the reservation counter at 1.
5. Cross-tenant isolation test seeds tenant-B cache + asserts `state.isInvalidated === false` post-mutation.
6. Each mutation's `onSuccess` is async + awaits the invalidate.

## Quality gate evidence (run by main session at fix SHA)

- `pnpm vitest run src/features/vouchers/__tests__/tenantScope.test.tsx`: 13/13 passing.
- `pnpm vitest run src/features/vouchers/`: 69/69 passing across 10 files (no regressions in pre-existing voucher component tests).
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 3119 events / 1205 callsites / 0 problems (post-submit).

## Verdict file

```
docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-7-codex-review.md
```

First non-empty line: `Commit reviewed: 052e763e`. The verdict line MUST be a literal `Verdict: APPROVE` (capital V, no `## VERDICT:` heading prefix — the strict CLI regex requires the bare line).

Cross-file shared identifier + sibling-namespace are the new shapes; if the predicate's namespace gate is sound and the reservation-settings rewrite cleanly replaces useCompany() with state-value selectors, expected verdict is APPROVE.
