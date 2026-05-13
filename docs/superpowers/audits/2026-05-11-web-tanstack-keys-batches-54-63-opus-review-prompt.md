# Opus Review Prompt: web.tanstack-keys Batches 54-63

You are Opus reviewing Codex implementation work for the web.tanstack-keys tenant-scope sweep.

## Scope

All listed callsites are submitted by Codex and awaiting independent Opus review/lock.

| Batch | Scope | Callsites | Fix commit | Scanner delta | Focused test |
| --- | --- | ---: | --- | --- | --- |
| B54 | attachment hooks | 182-185 | `c970e935` | 301 -> 297 | `apps/web/src/features/documents/hooks/__tests__/attachmentsTenantScope.test.tsx` |
| B55 | close-with-tolerance hook | 218-220 | `acd5def0` | 297 -> 294 | `apps/web/src/features/documents/invoices/hooks/__tests__/closeWithToleranceTenantScope.test.tsx` |
| B56 | finance account hooks | 269-272 | `bb46f0b6` | 294 -> 290 | `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx` |
| B57 | finance journal entry read hooks | 277-278 | `bb46f0b6` | 290 -> 288 | `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx` |
| B58 | finance journal entry mutation hooks | 279-281 | `bb46f0b6` | 288 -> 285 | `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx` |
| B59 | finance ledger hooks | 282-283 | `bb46f0b6` | 285 -> 283 | `apps/web/src/features/finance/hooks/__tests__/tenantScope.test.tsx` |
| B60 | treasury payment repository hooks | 716-717 | `ef1b1317` | 283 -> 281 | `apps/web/src/features/treasury/hooks/__tests__/paymentRepositoriesTenantScope.test.tsx` |
| B61 | user hooks | 748-749 | `85ed3918` | 281 -> 279 | `apps/web/src/features/users/hooks/__tests__/tenantScope.test.tsx` |
| B62 | VAT period action hooks | 750-751 | `4fe26b41` | 279 -> 277 | `apps/web/src/features/vat-reporting/hooks/__tests__/tenantScope.test.tsx` |
| B63 | VAT report hooks | 753-754 | `4fe26b41` | 277 -> 275 | `apps/web/src/features/vat-reporting/hooks/__tests__/tenantScope.test.tsx` |

## Review Gates

Use the same gates as prior approved batches:

1. Scanner delta matches each batch's submitted callsite count.
2. Read hooks use state-value tenant/company selectors and gate fetches until both are present.
3. Query keys are wrapped in `tenantScopedKey(...)`.
4. List/filter invalidations use active tenant/company predicates.
5. Detail invalidations use exact active-tenant keys.
6. Mutation invalidations are `async` and awaited.
7. Regression tests preserve tenant-B cache markers where mutations invalidate.
8. Regression tests use per-call counters for active-tenant refetches.
9. `php artisan sweep:inventory:verify-history` remains clean.

## Verification Already Run By Codex

```bash
pnpm vitest run \
  src/features/documents/hooks/__tests__/attachmentsTenantScope.test.tsx \
  src/features/documents/invoices/hooks/__tests__/closeWithToleranceTenantScope.test.tsx

pnpm vitest run src/features/finance/hooks/__tests__/tenantScope.test.tsx

pnpm vitest run \
  src/features/treasury/hooks/__tests__/paymentRepositoriesTenantScope.test.tsx \
  src/features/users/hooks/__tests__/tenantScope.test.tsx \
  src/features/vat-reporting/hooks/__tests__/tenantScope.test.tsx

pnpm typecheck
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'
php artisan sweep:inventory:verify-history
```

Final results:

```text
scanner: 275
verify-history: verified 4989 event(s) across 1205 callsite(s); 0 problem(s).
```

Focused Vitest runs pass with the same non-fatal React Query `act(...)` warnings seen in prior approved hook batches.

## Expected Verdict Action

If all gates pass, write the review file:

`docs/superpowers/reviews/2026-05-11-web-tanstack-keys-batches-54-63-opus-review.md`

Then lock:

```bash
cd apps/api

for id in 182 183 184 185; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=c970e935; done
for id in 218 219 220; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=acd5def0; done
for id in 269 270 271 272 277 278 279 280 281 282 283; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=bb46f0b6; done
for id in 716 717; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=ef1b1317; done
for id in 748 749; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=85ed3918; done
for id in 750 751 753 754; do php artisan sweep:inventory:lock --callsite-id="web.tanstack-keys.$id" --actor=opus --review-commit=4fe26b41; done

php artisan sweep:inventory:verify-history
```

If any gate fails, write `REQUEST-CHANGES` with exact file/line findings and do not lock the affected batch.
