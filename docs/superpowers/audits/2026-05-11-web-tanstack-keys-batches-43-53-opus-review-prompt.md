# Opus Review Prompt: web.tanstack-keys Batches 43-53

You are Opus reviewing Codex implementation work for the web.tanstack-keys tenant-scope sweep.

## Context

- Codex implements and submits.
- Opus independently reviews and locks.
- All callsites listed below are currently `under_review`.
- Current scanner count after B53: `301`.
- Review output target: `docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-43-53-opus-review.md`

## Review Gates

Use the same gates as prior approved batches:

1. Scanner delta matches each batch's submitted callsite count.
2. Read hooks use state-value tenant/company selectors and gate fetches until both values are present.
3. Query keys are wrapped in `tenantScopedKey(...)`.
4. List/filter invalidations use active tenant/company predicates.
5. Detail invalidations use exact active-tenant keys.
6. Cross-namespace invalidations are tenant/company scoped.
7. Mutation invalidations are `async` and awaited.
8. Regression tests preserve tenant-B cache markers.
9. Regression tests use per-call counters to prove intended active-tenant refetches and catch overfire.
10. `php artisan sweep:inventory:verify-history` remains clean.

## Submitted Batches

| Batch | Scope | Callsites | Fix commit | Metadata commit | Scanner delta | Focused test |
| --- | --- | ---: | --- | --- | --- | --- |
| B43 | workshop technician hooks | 811-825 | `b8b51f87` | `65ba119d` | 421 -> 406 | `apps/web/src/features/workshop-technicians/hooks/__tests__/tenantScope.test.tsx` |
| B44 | workshop work order hooks | 826-838 | `1538d2b2` | `11bb59b8` | 406 -> 393 | `apps/web/src/features/workshop-work-orders/hooks/__tests__/tenantScope.test.tsx` |
| B45 | vehicle hooks | 762-770 | `94fe0000` | `a1c882d8` | 393 -> 384 | `apps/web/src/features/vehicles/hooks/__tests__/tenantScope.test.tsx` |
| B46 | withholding hooks | 778-798 | `becd85fa` | `3a2d10ad` | 384 -> 363 | `apps/web/src/features/withholding/hooks/__tests__/tenantScope.test.tsx` |
| B47 | treasury reconciliation hooks | 718-734 | `0e5e4a6c` | `67be1392` | 363 -> 346 | `apps/web/src/features/treasury/hooks/__tests__/tenantScope.test.tsx` |
| B48 | batch hooks | 030-044 | `af17652e` | `a26f9517` | 346 -> 331 | `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx` |
| B49 | smart payment hooks | 735-739 | `cd730fd9` | `78f429c3` | 331 -> 326 | `apps/web/src/features/treasury/hooks/__tests__/smartPaymentTenantScope.test.tsx` |
| B50 | additional cost hooks | 174-181 | `c0448856` | `342e8732` | 326 -> 318 | `apps/web/src/features/documents/hooks/__tests__/additionalCostsTenantScope.test.tsx` |
| B51 | credit note hooks | 186-190 | `f5307e54` | `b7e111e6` | 318 -> 313 | `apps/web/src/features/documents/hooks/__tests__/creditNotesTenantScope.test.tsx` |
| B52 | return note hooks | 198-203 | `d1181b91` | `65916f46` | 313 -> 307 | `apps/web/src/features/documents/hooks/__tests__/returnNotesTenantScope.test.tsx` |
| B53 | delivery note hooks | 191-196 | `005bd90c` | `8afffc4e` | 307 -> 301 | `apps/web/src/features/documents/hooks/__tests__/deliveryNotesTenantScope.test.tsx` |

## B49-B53 Notes

- B49 scopes `smart-payment/tolerance-settings` and allocation invalidations for `payments`, exact `payment`, exact `invoice`, and `partner-balance`.
- B50 scopes additional-cost and landed-cost reads; create/update/delete also invalidate exact purchase-order document detail and landed-cost breakdown.
- B51 scopes credit-note reads; create invalidates active-scope `credit-notes`, exact source `invoice`, and active-scope `documents`.
- B52 scopes return-note reads; create invalidates active-scope `return-notes`, exact source `invoice`, exact source `delivery-note`, and active-scope `documents`.
- B53 scopes delivery-note reads; consolidation invalidates active-scope `delivery-notes`, `documents`, and `invoices`.

## Verification Already Run By Codex

For every batch above:

```bash
pnpm vitest run <focused test>
pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Final results after B53:

```text
scanner: 301
verify-history: verified 4911 event(s) across 1205 callsite(s); 0 problem(s).
```

The focused Vitest runs pass with the same non-fatal React Query `act(...)` warnings seen in prior approved hook batches.

## Expected Verdict Action

If all gates pass, write the review file with verdict `APPROVE` for each batch and lock using the batch fix commit:

```bash
cd apps/api

for id in $(seq 811 825); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=b8b51f87; done
for id in $(seq 826 838); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=1538d2b2; done
for id in $(seq 762 770); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=94fe0000; done
for id in $(seq 778 798); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=becd85fa; done
for id in $(seq 718 734); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=0e5e4a6c; done
for id in $(seq -f "%03g" 30 44); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=af17652e; done
for id in $(seq 735 739); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=cd730fd9; done
for id in $(seq 174 181); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=c0448856; done
for id in $(seq 186 190); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=f5307e54; done
for id in $(seq 198 203); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=d1181b91; done
for id in $(seq 191 196); do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=005bd90c; done

php artisan sweep:inventory:verify-history
```

If any gate fails, write `REQUEST-CHANGES` with exact file/line findings and do not lock that affected batch.
